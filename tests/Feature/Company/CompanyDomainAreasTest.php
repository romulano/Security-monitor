<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\Company;
use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;
use App\Models\NotificationChannel;
use App\Models\OwnedDomain;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\Webhook\WebhookConnector;
use App\Services\Alerts\Webhook\WebhookOwnership;
use App\Services\Company\ChannelScope;
use App\Services\Company\CompanyStamp;
use App\Services\Company\ItemSectors;
use App\Services\Company\Sectors;
use App\Services\Domains\DomainOwnership;
use App\Services\Lookalike\RadarInterview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\Support\OwnershipHelpers;
use Tests\TestCase;

/**
 * E3a2 (DESENHO-5.3 §2.4–2.6): a posse da empresa nas áreas — a regra de compatibilidade de setor (P1), o limite de 15,
 * o Radar olhando a empresa toda, o webhook só com o que a conta vê (P2), trocar e apagar setor, e o carimbo retroativo.
 */
class CompanyDomainAreasTest extends TestCase
{
    use OwnershipHelpers;
    use RefreshDatabase;
    use VisibilityFixtures;

    private FakeDnsResolver $dns;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private Sector $rh;

    private User $bruno;

    private User $carla;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->rh = $this->companySector($this->acme, 'RH');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
        $this->carla = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->carla, $this->rh, manager: true);
    }

    private function ownership(): DomainOwnership
    {
        return app(DomainOwnership::class);
    }

    private function companyDomain(string $domain, ?Sector $sector, bool $proven = true): OwnedDomain
    {
        return OwnedDomain::query()->forceCreate([
            'user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'sector_id' => $sector?->id, 'domain' => $domain,
            'source' => 'radar', 'proof_token' => $proven ? $this->acme->domain_token : null, 'verified_at' => $proven ? now() : null,
        ]);
    }

    private function refusedWith(callable $action): string
    {
        try {
            $action();
        } catch (ActionRefused|ValidationException $e) {
            return $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
        }

        $this->fail('Aceitou o que devia recusar.');
    }

    public function test_an_item_only_uses_a_domain_without_sector_or_of_its_own_sector(): void
    {
        $this->companyDomain('ti.acme.com.br', $this->ti);
        $this->companyDomain('acme.com.br', null);
        $radar = app(RadarInterview::class);

        // Outro setor e "sem setor" não usam o domínio do TI, e a recusa não diz o setor.
        $message = $this->refusedWith(fn () => $radar->saveDomain($this->carla, null, null, 'ti.acme.com.br', $this->rh->id));
        $this->assertSame(DomainOwnership::OTHER_SECTOR, $message);
        $this->assertStringNotContainsString('TI', $message);
        $this->assertSame(DomainOwnership::OTHER_SECTOR, $this->refusedWith(fn () => $radar->saveDomain($this->ana, null, null, 'ti.acme.com.br', null)));
        $this->assertSame(0, LookalikeWatch::query()->count(), 'nada ficou pela metade');

        // O próprio setor usa, já comprovado; o domínio sem setor serve a qualquer setor.
        $this->assertTrue($radar->saveDomain($this->bruno, null, null, 'ti.acme.com.br', $this->ti->id)->domain->isVerified());
        $this->assertTrue($radar->saveDomain($this->carla, null, null, 'acme.com.br', $this->rh->id)->domain->isVerified());
    }

    public function test_the_parent_of_another_sector_does_not_cover_the_item(): void
    {
        $this->companyDomain('acme.com.br', $this->ti, proven: false);
        $this->dns->set('acme.com.br', 'txt', [DomainOwnership::PREFIX.$this->acme->domain_token]);
        $this->dns->set('loja.acme.com.br', 'txt', []);

        // O alerta do RH com loja.acme.com.br: o domínio de cima é do TI e não serve ao RH, então não cobre o nome (Y1 do
        // Red Team da E3b1): o RH fica com a linha própria do nome, que o TXT no domínio de cima não comprova.
        $rh = $this->profileOf($this->carla, $this->acme, $this->rh);
        $loja = $rh->domains()->create(['domain' => 'loja.acme.com.br', 'verify_token' => $this->acme->domain_token]);
        $this->assertFalse($this->ownership()->verifyRow($loja, 'news'));
        $this->assertFalse($loja->refresh()->isVerified());
        $this->assertSame($this->rh->id, OwnedDomain::query()->where('domain', 'loja.acme.com.br')->value('sector_id'));

        // O alerta do TI, sim: o TXT da empresa no domínio de cima comprova a linha do TI, que cobre o nome.
        $ti = $this->profileOf($this->bruno, $this->acme, $this->ti);
        $this->assertTrue($this->ownership()->verifyRow($ti->domains()->create(['domain' => 'app.acme.com.br', 'verify_token' => $this->acme->domain_token]), 'news'));
        $this->assertNotNull(OwnedDomain::query()->where('domain', 'acme.com.br')->value('verified_at'));
    }

    public function test_the_company_limit_counts_pending_domains_too(): void
    {
        $this->acme->forceFill(['limit_domains' => 2])->save();
        $this->companyDomain('um.com.br', null, proven: false);
        $radar = app(RadarInterview::class);
        $radar->saveDomain($this->ana, null, null, 'dois.com.br', null);

        $message = $this->refusedWith(fn () => $radar->saveDomain($this->ana, null, null, 'tres.com.br', null));
        $this->assertSame('A empresa chegou ao limite de 2 domínios. Remova um em Domínios da empresa para cadastrar outro.', $message);
        $this->assertSame(2, OwnedDomain::query()->where('company_id', $this->acme->id)->count());

        // A conta pessoal não tem esse limite, e o Admin → Empresas mostra o uso.
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.companies.index'))
            ->assertSeeInOrder(['Domínios', '2', '/ 2']);
    }

    public function test_the_radar_looks_at_the_whole_company_and_keeps_the_creator(): void
    {
        $radar = app(RadarInterview::class);
        $tiWatch = $radar->saveDomain($this->bruno, null, null, 'portal.com.br', $this->ti->id);

        // O RH não vê o Radar do TI, mas não cria outro para o mesmo domínio; a mensagem não diz de qual setor ele é.
        $message = $this->refusedWith(fn () => $radar->saveDomain($this->carla, null, null, 'portal.com.br', $this->rh->id));
        $this->assertSame('Já existe um Radar para portal.com.br na empresa.', $message);
        $this->assertNotContains('portal.com.br', $radar->provenDomains($this->carla)->pluck('domain')->all());

        // A admin troca o domínio do rascunho do Bruno: a cópia continua dele, e a posse é da empresa.
        $radar->saveDomain($this->ana, $tiWatch, null, 'portal2.com.br');
        $copy = $tiWatch->refresh()->domain;
        $this->assertSame([$this->bruno->id, $this->acme->domain_token], [$copy->user_id, $copy->verify_token]);
        $this->assertSame([$this->acme->id, $this->ti->id], [OwnedDomain::query()->where('domain', 'portal2.com.br')->value('company_id'), OwnedDomain::query()->where('domain', 'portal2.com.br')->value('sector_id')]);
        $this->assertSame(0, OwnedDomain::query()->whereNull('company_id')->count(), 'nada na posse pessoal de quem editou');
    }

    public function test_the_radar_does_not_take_the_consent_of_an_alert_it_does_not_manage(): void
    {
        $this->companyDomain('acme.com.br', null);
        $alert = $this->profileOf($this->carla, $this->acme, $this->rh);
        $alert->domains()->create(['domain' => 'acme.com.br', 'verify_token' => $this->acme->domain_token, 'consent_at' => now(), 'consent_version' => 'v3']);
        $radar = app(RadarInterview::class);
        $proven = $radar->provenDomains($this->bruno)->firstWhere('domain', 'acme.com.br');

        $watch = $radar->saveDomain($this->bruno, null, $proven->id, null, $this->ti->id);
        $this->assertNull($watch->domain->consent_at, 'o aceite do alerta do RH não vem para o Radar do TI');
    }

    // E5a (DESENHO-5.4 §2.3): o webhook da empresa é um canal da empresa, geral ou de setor; a posse é a da empresa que
    // serve ao escopo dele (a regra de compatibilidade), e ele nunca cadastra domínio.
    public function test_the_company_webhook_only_uses_the_domains_of_its_scope_and_never_registers_a_domain(): void
    {
        $caio = $this->companyAccount($this->acme);
        $this->joinSector($caio, $this->rh);
        $this->companyDomain('ti.acme.com.br', $this->ti);
        $connector = app(WebhookConnector::class);
        $session = $this->app['session.store'];
        $rh = ChannelScope::sector($this->acme->id, $this->rh->id);

        // Conta de empresa não tem webhook pessoal, e membro não conecta canal (achado 2 do DESENHO-5.4).
        $this->assertSame('Escolha onde o webhook recebe: nos gerais da empresa ou num setor.', $this->refusedWith(fn () => $connector->create($caio, 'Prod', 'https://hooks.acme.com.br/a', 'nenhuma', null, $session)));
        $this->assertStringStartsWith('Só quem responde pelo setor', $this->refusedWith(fn () => $connector->create($caio, 'Prod', 'https://hooks.acme.com.br/a', 'nenhuma', null, $session, $rh)));

        // Host de um domínio do TI (que o setor RH não usa) ou de nenhum domínio: recusa, e nada é cadastrado.
        foreach (['https://hooks.ti.acme.com.br/a', 'https://hooks.outro.com.br/a'] as $url) {
            $this->assertSame(WebhookOwnership::COMPANY_PROOF, $this->refusedWith(fn () => $connector->create($this->carla, 'Prod', $url, 'nenhuma', null, $session, $rh)));
        }
        $this->assertSame(1, OwnedDomain::query()->count());
        $this->assertSame(0, NotificationChannel::query()->count());
        $this->assertNotContains('ti.acme.com.br', $connector->newPage($this->carla, $rh)['proven']->all());
        // Nos gerais, nem quem administra usa o domínio do TI.
        $this->assertSame(WebhookOwnership::COMPANY_PROOF, $this->refusedWith(fn () => $connector->create($this->ana, 'Geral', 'https://hooks.ti.acme.com.br/a', 'nenhuma', null, $session, ChannelScope::general($this->acme->id))));
        // No TI, sim.
        $this->assertSame(['ti.acme.com.br'], $connector->newPage($this->bruno, ChannelScope::sector($this->acme->id, $this->ti->id))['proven']->all());

        // Domínio sem setor comprovado: o webhook do RH nasce ativo, da empresa, sem posse pessoal, e entra na Atividade.
        $owned = $this->companyDomain('acme.com.br', null);
        $webhook = $connector->create($this->carla, 'Prod', 'https://hooks.acme.com.br/a', 'nenhuma', null, $session, $rh);
        $this->assertSame(['active', $this->acme->id, $this->rh->id, $this->carla->id], [$webhook->status, $webhook->company_id, $webhook->sector_id, $webhook->user_id]);
        $this->assertTrue(app(WebhookOwnership::class)->holds($webhook));
        $this->assertSame(['acme.com.br'], $connector->newPage($this->carla, $rh)['proven']->all());
        $this->assertSame(0, OwnedDomain::query()->whereNull('company_id')->count());
        $this->assertFalse($this->carla->webhooks()->exists(), 'o webhook da empresa não é "meu" webhook');
        $this->assertTrue(DB::table('company_audit')->where('action', 'channel.added')->where('target_label', 'Webhook Prod')->exists());

        // A posse da empresa cai: o webhook para junto.
        $this->dns->set('acme.com.br', 'txt', []);
        $this->ownership()->recheck($owned);
        $this->travel(8)->days();
        $this->ownership()->recheck($owned->refresh());
        $this->assertFalse(app(WebhookOwnership::class)->holds($webhook->refresh()));
    }

    public function test_moving_the_sector_keeps_the_domain_rule(): void
    {
        $this->companyDomain('ti.acme.com.br', $this->ti);
        $this->companyDomain('acme.com.br', null);
        $radar = app(RadarInterview::class);
        $tiWatch = $radar->saveDomain($this->ana, null, null, 'ti.acme.com.br', $this->ti->id);
        $openWatch = $radar->saveDomain($this->ana, null, null, 'acme.com.br', $this->ti->id);
        $sectors = app(ItemSectors::class);

        // O Radar do domínio do TI não vai para o RH; o do domínio sem setor vai, e continua comprovado.
        $this->refusedWith(fn () => $sectors->move($this->ana, $tiWatch, $this->rh->id, 'Radar', 'ti.acme.com.br'));
        $this->assertSame($this->ti->id, $tiWatch->refresh()->sector_id);
        $sectors->move($this->ana, $openWatch, $this->rh->id, 'Radar', 'acme.com.br');
        $this->assertTrue($openWatch->refresh()->domain->isVerified());

        // O domínio do TI não vai para o RH enquanto um Radar do TI depende dele; sem setor, vai.
        $domain = OwnedDomain::query()->where('domain', 'ti.acme.com.br')->sole();
        $this->assertSame(1, $this->ownership()->blockingItems($domain, $this->rh->id));
        $this->assertStringContainsString('1 alerta ou Radar de outro setor usa este domínio', $this->refusedWith(fn () => $sectors->move($this->ana, $domain, $this->rh->id, 'Domínio', 'ti.acme.com.br')));
        $sectors->move($this->ana, $domain, null, 'Domínio', 'ti.acme.com.br');
        $this->assertNull($domain->refresh()->sector_id);
    }

    public function test_deleting_the_sector_counts_and_frees_its_domains(): void
    {
        $domain = $this->companyDomain('ti.acme.com.br', $this->ti);
        $this->ana->forceFill(['password' => 'senha-da-admin-1'])->save();
        $counts = app(Sectors::class)->counts($this->ti);
        $this->assertSame(1, $counts['dominios']);
        $this->assertStringContainsString('1 domínio passa a ser visto pela empresa toda', Sectors::summary($counts));
        // O setor sem domínio continua com a contagem de antes (o diálogo e a Atividade não mudam).
        $this->assertArrayNotHasKey('dominios', app(Sectors::class)->counts($this->rh));

        $this->actingAs($this->ana)->delete(route('company.sectors.delete'), [
            'setor' => $this->ti->id.'-'.implode('-', $counts), 'reauth_password' => 'senha-da-admin-1',
        ])->assertSessionHasNoErrors();
        $this->assertNull(Sector::query()->find($this->ti->id));
        $this->assertSame([$this->acme->id, null], [$domain->refresh()->company_id, $domain->sector_id]);
    }

    public function test_the_stamp_passes_the_old_personal_proof_to_the_company_once_per_name(): void
    {
        $pedro = User::factory()->create();
        // Antes da E3a1: o Bruno e a Carla pediram o mesmo nome; só a Carla comprovou. O Bruno também pediu um nome que a
        // empresa já tem. O Pedro (pessoal) não muda.
        $old = fn (User $user, string $domain, bool $proven) => OwnedDomain::query()->forceCreate([
            'user_id' => $user->id, 'domain' => $domain, 'source' => 'radar', 'tokens' => ['tokantigo'],
            'proof_token' => $proven ? 'tokantigo' : null, 'verified_at' => $proven ? now()->subMonth() : null, 'legacy' => $proven,
        ]);
        $brunoLoja = $old($this->bruno, 'loja.com.br', false);
        $carlaLoja = $old($this->carla, 'loja.com.br', true);
        $old($this->bruno, 'acme.com.br', true);
        $company = $this->companyDomain('acme.com.br', null, proven: false);
        $pedroLoja = $old($pedro, 'loja.com.br', true);
        $watch = $this->watchOf($this->carla, $this->acme);
        $watch->domain->forceFill(['domain' => 'loja.com.br', 'verify_token' => 'tokantigo'])->save();

        $this->assertSame(['dominios' => 1, 'repetidos' => 2], app(CompanyStamp::class)->domains(dryRun: true));
        $this->artisan('secmon:company-stamp')->expectsOutput('Domínios passados à empresa: 1; linhas repetidas que saíram: 2.')->assertSuccessful();

        // Fica a comprovada (a da Carla), da empresa, sem setor e sem os tokens da conta; a do Bruno sai.
        $kept = $carlaLoja->refresh();
        $this->assertSame([$this->acme->id, null, [], null, false, null], [$kept->company_id, $kept->sector_id, $kept->tokens, $kept->proof_token, $kept->legacy, $kept->checked_at]);
        $this->assertNotNull($kept->verified_at, 'a prova continua até a próxima conferência');
        $this->assertModelMissing($brunoLoja);
        $this->assertSame([$company->id], OwnedDomain::query()->where('domain', 'acme.com.br')->pluck('id')->all(), 'a da empresa ganha');
        $this->assertSame($pedro->id, $pedroLoja->refresh()->user_id);
        $this->assertNull($pedroLoja->company_id);
        $this->assertSame($this->acme->domain_token, $watch->domain->refresh()->verify_token, 'a cópia mostra o TXT da empresa');

        // Uma vez só.
        $this->assertSame(['dominios' => 0, 'repetidos' => 0], app(CompanyStamp::class)->domains());
    }

    public function test_the_scheduler_skips_what_the_company_rule_refuses(): void
    {
        $this->companyDomain('ti.acme.com.br', $this->ti, proven: false);
        $rh = $this->profileOf($this->carla, $this->acme, $this->rh);
        DB::table('monitored_domains')->insert(['profile_id' => $rh->id, 'user_id' => $this->carla->id, 'domain' => 'ti.acme.com.br',
            'verify_token' => $this->acme->domain_token, 'consent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('secmon:alerts-verify-domains')->assertSuccessful();
        $this->assertNull(MonitoredDomain::query()->where('profile_id', $rh->id)->value('verified_at'));
    }
}
