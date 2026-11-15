<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Mail\CompanyDomainNotice;
use App\Models\Company;
use App\Models\EmailMessage;
use App\Models\OwnedDomain;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Company\Companies;
use App\Services\Company\CompanyDomainNotices;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\Support\OwnershipHelpers;
use Tests\TestCase;

/** E3d (DESENHO-5.3 §4): os e-mails de domínio da empresa — o TXT sumiu, a posse caiu, o limite em 80% e 100%. */
class CompanyDomainNoticesTest extends TestCase
{
    use OwnershipHelpers;
    use RefreshDatabase;
    use VisibilityFixtures;

    private FakeDnsResolver $dns;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private User $bruno;

    private User $carla;

    private User $caio;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $rh = $this->companySector($this->acme, 'RH');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
        $this->carla = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->carla, $rh, manager: true);
        $this->caio = $this->companyAccount($this->acme);
        $this->joinSector($this->caio, $this->ti);
    }

    private function ownership(): DomainOwnership
    {
        return app(DomainOwnership::class);
    }

    private function companyDomain(string $domain, ?Sector $sector): OwnedDomain
    {
        return OwnedDomain::query()->forceCreate([
            'user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'sector_id' => $sector?->id, 'domain' => $domain,
            'source' => 'radar', 'proof_token' => $this->acme->domain_token, 'verified_at' => now()->subMonth(),
        ]);
    }

    /** @return list<string> os destinatários dos e-mails deste tipo, em ordem */
    private function sentTo(string $kind): array
    {
        return EmailMessage::query()->where('kind', $kind)->orderBy('id')->pluck('to_address')->all();
    }

    public function test_the_txt_gone_warns_on_the_first_day_and_the_loss_tells_what_stopped(): void
    {
        $owned = $this->companyDomain('ti.acme.com.br', $this->ti);
        $watch = $this->watchOf($this->bruno, $this->acme, $this->ti);
        $watch->domain->forceFill(['domain' => 'ti.acme.com.br', 'verified_at' => now()])->save();
        $this->dns->set('ti.acme.com.br', 'txt', []);

        // 1º dia sem o TXT: a admin e o responsável do TI (quem configura); nem o RH nem o membro do TI.
        $this->ownership()->recheck($owned);
        $this->assertSame(['ana@acme.com', $this->bruno->email], $this->sentTo('empresa-dominio'));
        $this->assertSame('Security Monitor: o TXT de ti.acme.com.br sumiu — a posse cai em 7 dias', EmailMessage::query()->value('subject'));

        // Os dias seguintes sem o TXT não repetem.
        $this->travel(2)->days();
        $this->ownership()->recheck($owned->refresh());
        $this->assertCount(2, $this->sentTo('empresa-dominio'));

        // Caiu: o segundo aviso, com o que parou e o TXT da empresa no texto.
        $this->travel(6)->days();
        $this->ownership()->recheck($owned->refresh());
        $this->assertNull($owned->refresh()->verified_at);
        $this->assertSame(['ana@acme.com', $this->bruno->email, 'ana@acme.com', $this->bruno->email], $this->sentTo('empresa-dominio'));
        $this->assertSame(2, EmailMessage::query()->where('subject', 'Security Monitor: ti.acme.com.br perdeu a comprovação de posse')->count());

        $mail = new CompanyDomainNotice('lost', 'Ana', 'Acme', 'ti.acme.com.br', DomainOwnership::PREFIX.$this->acme->domain_token, $owned->unseen_since, null, ['1 Radar'], url('/empresa/dominios'));
        $mail->assertSeeInText('Pararam até a posse voltar: 1 Radar.');
        $mail->assertSeeInText(DomainOwnership::PREFIX.$this->acme->domain_token);
        $this->assertFalse($watch->domain->refresh()->isVerified());
    }

    public function test_two_rechecks_at_the_same_time_send_each_notice_once(): void
    {
        $owned = $this->companyDomain('acme.com.br', null);
        $this->dns->set('acme.com.br', 'txt', []);
        // Duas reconferências que leram a linha antes de qualquer uma gravar (o agendador e o "Verificar agora").
        $stale = OwnedDomain::query()->find($owned->id);

        $this->ownership()->recheck($owned);
        $this->ownership()->recheck($stale);
        $this->assertCount(1, $this->sentTo('empresa-dominio'), 'um aviso do 1º dia');

        $this->travel(8)->days();
        $first = $owned->refresh();
        $second = OwnedDomain::query()->find($owned->id);
        $this->ownership()->recheck($first);
        $this->ownership()->recheck($second);
        $this->assertCount(2, $this->sentTo('empresa-dominio'), 'um aviso da queda');
        $this->assertNull($owned->refresh()->verified_at);
    }

    public function test_a_domain_without_sector_warns_only_the_admin_and_the_personal_account_gets_nothing(): void
    {
        $this->dns->set('acme.com.br', 'txt', [])->set('pedro.com.br', 'txt', []);
        $this->ownership()->recheck($this->companyDomain('acme.com.br', null));
        $this->assertSame(['ana@acme.com'], $this->sentTo('empresa-dominio'));

        // A conta pessoal continua como sempre: nenhum e-mail novo, nem no 1º dia, nem na queda.
        $pedro = User::factory()->create();
        $mine = $this->proveDomain($pedro->id, 'pedro.com.br');
        $this->ownership()->recheck($mine);
        $this->travel(8)->days();
        $this->ownership()->recheck($mine->refresh());
        $this->assertNull($mine->refresh()->verified_at);
        $this->assertSame(0, EmailMessage::query()->where('user_id', $pedro->id)->count());
    }

    public function test_a_suspended_company_gets_no_domain_email(): void
    {
        app(Companies::class)->suspend($this->acme);
        $this->dns->set('acme.com.br', 'txt', []);

        $this->ownership()->recheck($this->companyDomain('acme.com.br', null));

        $this->assertSame([], $this->sentTo('empresa-dominio'));
    }

    public function test_the_domain_limit_warns_once_at_80_and_at_100_percent(): void
    {
        $this->acme->forceFill(['limit_domains' => 5])->save();
        $owner = DomainOwner::company($this->acme->id, $this->ana->id);
        $claim = fn (string $name) => $this->ownership()->claim($owner, $name, 'radar');

        foreach (['a.com.br', 'b.com.br', 'c.com.br'] as $name) {
            $claim($name);
        }
        $this->assertSame([], $this->sentTo('empresa-limite'));

        // 4 de 5 = 80%: só a admin (o limite é da empresa); cadastrar o mesmo nome de novo não conta nem repete.
        $claim('d.com.br');
        $claim('d.com.br');
        $this->assertSame(['ana@acme.com'], $this->sentTo('empresa-limite'));
        $this->assertSame('Security Monitor: Acme usou 4 de 5 domínios', EmailMessage::query()->where('kind', 'empresa-limite')->value('subject'));

        $claim('e.com.br');
        $this->assertSame('Security Monitor: Acme chegou ao limite de domínios', EmailMessage::query()->where('kind', 'empresa-limite')->latest('id')->value('subject'));

        // Removeu um (a tela de domínios chama o mesmo aviso): voltou a 80%, e o de 100% pode sair de novo.
        OwnedDomain::query()->where('domain', 'e.com.br')->delete();
        app(CompanyDomainNotices::class)->domains($this->acme->id);
        $this->assertSame([80], DB::table('company_limit_notices')->pluck('level')->all());
        $claim('f.com.br');
        $this->assertSame(3, EmailMessage::query()->where('kind', 'empresa-limite')->count());
    }

    public function test_the_subdomain_limit_goes_to_whoever_configures_the_domain(): void
    {
        // O ponto da E3b: o limite de subdomínios é por domínio (escopo), e vai também a quem responde pelo setor dele.
        $owned = $this->companyDomain('ti.acme.com.br', $this->ti);
        $other = $this->companyDomain('rh.acme.com.br', null);
        $notices = app(CompanyDomainNotices::class);

        $notices->limit($this->acme, 'subdomains', 20, 20, $owned);
        $notices->limit($this->acme, 'subdomains', 16, 20, $other);
        $notices->limit($this->acme, 'subdomains', 20, 20, $owned);

        $this->assertSame(['ana@acme.com', $this->bruno->email, 'ana@acme.com'], $this->sentTo('empresa-limite'));
        $this->assertSame('Security Monitor: Acme chegou ao limite de subdomínios de ti.acme.com.br', EmailMessage::query()->where('kind', 'empresa-limite')->orderBy('id')->value('subject'));
        $this->assertSame(3, DB::table('company_limit_notices')->count(), 'os dois marcos do 1º e o de 80% do 2º');
    }
}
