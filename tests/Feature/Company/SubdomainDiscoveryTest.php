<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Jobs\DiscoverSubdomains;
use App\Models\Company;
use App\Models\CompanyAuditEntry;
use App\Models\EmailMessage;
use App\Models\OwnedDomain;
use App\Models\OwnedSubdomain;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Company\Companies;
use App\Services\Company\CompanyAudit;
use App\Services\Domains\SubdomainDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\Support\OwnershipHelpers;
use Tests\TestCase;

/**
 * E3c (DESENHO-5.3 §3.3): a descoberta passiva dos subdomínios. O crt.sh responde pelo Http::fake e o DNS é o dublê:
 * nenhum teste sai para a rede.
 */
class SubdomainDiscoveryTest extends TestCase
{
    use OwnershipHelpers;
    use RefreshDatabase;
    use VisibilityFixtures;

    private FakeDnsResolver $dns;

    private Company $acme;

    private User $ana;

    private User $bruno;

    private OwnedDomain $domain;

    /** @var list<string> o que o crt.sh devolve (ou um código HTTP de erro) */
    private array|int $crtsh = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(DnsResolver::class, $this->dns = new FakeDnsResolver);
        Http::fake(['crt.sh/*' => fn () => is_int($this->crtsh) ? Http::response('erro', $this->crtsh) : Http::response(array_map(fn (string $name) => ['name_value' => $name], $this->crtsh))]);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $ti = $this->companySector($this->acme, 'TI');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $ti, manager: true);
        $this->domain = OwnedDomain::query()->forceCreate([
            'user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'sector_id' => $ti->id, 'domain' => 'acme.com.br', 'source' => 'company',
            'proof_token' => $this->acme->domain_token, 'verified_at' => now()->subMonth(),
        ]);
    }

    private function discover(?OwnedDomain $domain = null): ?array
    {
        return app(SubdomainDiscovery::class)->run($domain ?? $this->domain);
    }

    private function up(string $name): void
    {
        $this->dns->set($name, 'ips', ['203.0.113.10']);
    }

    /** @return array<string, array{0: string, 1: ?string, 2: string}> nome => [status, motivo, origem] */
    private function subdomains(): array
    {
        return OwnedSubdomain::query()->orderBy('name')->get()->mapWithKeys(fn (OwnedSubdomain $s) => [$s->name => [$s->status, $s->reason, $s->origin]])->all();
    }

    public function test_only_what_resolves_inside_the_company_zone_turns_active(): void
    {
        $this->crtsh = ["login.acme.com.br\n*.acme.com.br", 'loja.acme.com.br', 'app.acme.com.br', 'nada.acme.com.br', 'mudo.acme.com.br', 'acme.com.br', 'xacme.com.br', 'login.outra.com.br'];
        $this->up('login.acme.com.br');
        $this->dns->set('loja.acme.com.br', 'cname', ['shops.myshopify.com']);
        $this->up('shops.myshopify.com');
        $this->dns->set('app.acme.com.br', 'ns', ['ns1.agencia.com']);
        $this->up('app.acme.com.br');
        $this->dns->failing['mudo.acme.com.br'] = 9;

        $summary = $this->discover();

        // Ativo só o que resolve e está na zona (B2); CNAME para terceiro e delegação de fora viram sugestão com o motivo;
        // o que não resolve e o DNS mudo não são gravados (voltam amanhã, se ainda estiverem nos certificados).
        $this->assertSame([
            'app.acme.com.br' => ['suggested', 'delegado', 'passive'],
            'login.acme.com.br' => ['active', null, 'passive'],
            'loja.acme.com.br' => ['suggested', 'cname_fora', 'passive'],
        ], $this->subdomains());
        $this->assertSame(['novos' => 3, 'ativos' => 1, 'sugestoes' => 2, 'saíram' => 0, 'erro' => null], $summary);
        $this->assertNotNull($this->domain->refresh()->subdomains_checked_at);
        $this->assertSame(1, OwnedDomain::query()->count(), 'nada vira domínio, nada conta nos 15');
        $this->assertSame('Descoberta automática de subdomínios em acme.com.br: 1 ativos, 2 sugestões',
            app(CompanyAudit::class)->describe(CompanyAuditEntry::query()->where('action', 'subdomains.discovered')->sole()));

        // A 2ª execução não muda o que já existe (a sugestão continua sugestão).
        $this->assertSame(['novos' => 0, 'ativos' => 0, 'sugestoes' => 0, 'saíram' => 0, 'erro' => null], $this->discover());
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'q=%.acme.com.br'));
    }

    public function test_above_the_ceiling_it_suggests_and_warns(): void
    {
        $this->acme->forceFill(['limit_subdomains' => 2])->save();
        $this->crtsh = ['a.acme.com.br', 'b.acme.com.br', 'c.acme.com.br'];
        array_map(fn ($l) => $this->up("{$l}.acme.com.br"), ['a', 'b', 'c']);

        $this->discover();

        $this->assertSame(['a.acme.com.br' => ['active', null, 'passive'], 'b.acme.com.br' => ['active', null, 'passive'], 'c.acme.com.br' => ['suggested', 'teto', 'passive']], $this->subdomains());
        // 2 de 2: o aviso de 100% vai à admin e ao responsável do setor do domínio, uma vez.
        $this->assertSame(['ana@acme.com', $this->bruno->email], EmailMessage::query()->where('kind', 'empresa-limite')->orderBy('id')->pluck('to_address')->all());
        $this->discover();
        $this->assertSame(2, EmailMessage::query()->where('kind', 'empresa-limite')->count());
    }

    public function test_what_people_decided_stays_and_a_gone_name_comes_back(): void
    {
        $this->crtsh = ['tirado.acme.com.br', 'velho.acme.com.br'];
        $this->up('tirado.acme.com.br');
        $this->up('velho.acme.com.br');
        $this->domain->subdomains()->forceCreate(['name' => 'tirado.acme.com.br', 'origin' => 'manual', 'status' => 'ignored', 'first_seen_at' => now()]);
        $this->domain->subdomains()->forceCreate(['name' => 'velho.acme.com.br', 'origin' => 'passive', 'status' => 'gone', 'reason' => 'sem_ip', 'first_seen_at' => now()->subMonth()]);

        $this->discover();

        $this->assertSame(['tirado.acme.com.br' => ['ignored', null, 'manual'], 'velho.acme.com.br' => ['active', null, 'passive']], $this->subdomains());
    }

    public function test_the_active_ones_are_checked_every_day(): void
    {
        $this->up('fora.acme.com.br');
        $sub = fn (string $name, string $origin) => $this->domain->subdomains()->forceCreate(['name' => $name, 'origin' => $origin, 'status' => 'active',
            'first_seen_at' => now(), 'last_seen_at' => now(), 'activated_at' => now()]);
        $sub('caiu.acme.com.br', 'passive');
        $sub('virou.acme.com.br', 'passive');
        $sub('manual.acme.com.br', 'manual');
        $sub('fora.acme.com.br', 'passive');
        // O passivo e o manual passam a apontar para a loja de um terceiro; o caiu.acme.com.br sai do ar.
        foreach (['virou', 'manual'] as $label) {
            $this->dns->set("{$label}.acme.com.br", 'cname', ['shops.myshopify.com']);
        }
        $this->up('shops.myshopify.com');

        $this->discover();
        $this->assertSame([
            'caiu.acme.com.br' => ['active', null, 'passive'],
            'fora.acme.com.br' => ['active', null, 'passive'],
            'manual.acme.com.br' => ['active', 'cname_fora', 'manual'],
            'virou.acme.com.br' => ['suggested', 'cname_fora', 'passive'],
        ], $this->subdomains(), 'fora do ar há pouco fica; o passivo de fora vira sugestão; o manual fica com a marca (P5)');

        $this->travel(8)->days();
        $this->discover();
        $this->assertSame(['gone', 'sem_ip'], array_slice($this->subdomains()['caiu.acme.com.br'], 0, 2), '7 dias sem endereço: sai e libera a vaga');
        $this->assertSame('active', $this->subdomains()['fora.acme.com.br'][0]);
    }

    public function test_a_failing_crtsh_is_recorded_and_the_active_ones_are_still_checked(): void
    {
        $this->crtsh = 502;
        $active = $this->domain->subdomains()->forceCreate(['name' => 'x.acme.com.br', 'origin' => 'passive', 'status' => 'active', 'first_seen_at' => now(), 'last_seen_at' => now()->subDay()]);
        $this->up('x.acme.com.br');

        $this->assertSame('crt.sh indisponível (HTTP 502)', $this->discover()['erro']);
        $this->assertSame('crt.sh indisponível (HTTP 502)', $this->domain->refresh()->subdomains_error);
        $this->assertTrue($active->refresh()->last_seen_at->isToday());
    }

    public function test_nothing_runs_for_a_domain_out_of_the_rule(): void
    {
        $this->crtsh = ['a.acme.com.br'];
        $pending = OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'domain' => 'pendente.com.br', 'source' => 'company']);
        $unseen = OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'domain' => 'sumiu.com.br', 'source' => 'company',
            'proof_token' => $this->acme->domain_token, 'verified_at' => now()->subMonth(), 'unseen_since' => now()->subDay()]);
        $personal = $this->proveDomain(User::factory()->create()->id, 'pessoal.com.br');

        foreach ([$pending, $unseen, $personal] as $domain) {
            $this->assertNull($this->discover($domain), $domain->domain);
        }
        app(Companies::class)->suspend($this->acme);
        $this->assertNull($this->discover());
        Http::assertNothingSent();
    }

    public function test_the_scheduler_queues_the_due_domains_on_the_low_priority_queue(): void
    {
        config(['secmon.subdomains.per_run' => 2]);
        $recent = OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'domain' => 'recente.com.br', 'source' => 'company',
            'proof_token' => $this->acme->domain_token, 'verified_at' => now(), 'subdomains_checked_at' => now()->subHour()]);
        $old = OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'domain' => 'antigo.com.br', 'source' => 'company',
            'proof_token' => $this->acme->domain_token, 'verified_at' => now(), 'subdomains_checked_at' => now()->subDays(2)]);
        OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'domain' => 'terceiro.com.br', 'source' => 'company',
            'proof_token' => $this->acme->domain_token, 'verified_at' => now(), 'subdomains_checked_at' => now()->subDays(1)->subHour()]);
        OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'domain' => 'pendente.com.br', 'source' => 'company']);
        $this->proveDomain(User::factory()->create()->id, 'pessoal.com.br');

        $this->artisan('secmon:subdomains-discover --due')->expectsOutput('2 domínio(s) na fila `subdomains`.')->assertSuccessful();

        // Os mais atrasados primeiro (o nunca conferido, depois o de 2 dias), até o teto da rodada; na fila `subdomains`.
        $queued = [];
        Queue::assertPushedOn('subdomains', DiscoverSubdomains::class, function (DiscoverSubdomains $job) use (&$queued) {
            $queued[] = $job->ownedDomainId;

            return true;
        });
        $this->assertSame([$this->domain->id, $old->id], $queued);
        $this->assertNotContains($recent->id, $queued);

        // A fila no fim do worker de baixa prioridade, antes da `default`.
        $this->assertStringContainsString('--queue=judge,pentest-ai,enrich,discovery,github,merge,subdomains,default ', (string) file_get_contents(base_path('docker/supervisor/secmon.conf')));
    }

    public function test_the_run_has_a_ceiling_of_new_names(): void
    {
        config(['secmon.subdomains.new_per_run' => 2]);
        $this->crtsh = ['a.acme.com.br', 'b.acme.com.br', 'c.acme.com.br'];
        array_map(fn ($l) => $this->up("{$l}.acme.com.br"), ['a', 'b', 'c']);

        $this->assertSame(2, $this->discover()['novos']);
        $this->assertSame(1, $this->discover()['novos'], 'o resto fica para a próxima');
    }

    public function test_dead_names_wait_a_week_so_the_new_ones_get_their_turn(): void
    {
        config(['secmon.subdomains.new_per_run' => 2]);
        $this->crtsh = ['a.acme.com.br', 'b.acme.com.br', 'c.acme.com.br', 'd.acme.com.br'];
        $this->up('c.acme.com.br');
        $this->up('d.acme.com.br');

        // 1º dia: a e b não resolvem (não são gravados); 2º dia: a vez é do c e do d, não de a e b de novo.
        $this->assertSame(0, $this->discover()['novos']);
        $this->assertSame(2, $this->discover()['novos']);
        $this->assertSame(['c.acme.com.br', 'd.acme.com.br'], array_keys($this->subdomains()));

        // Uma semana depois, a e b voltam a ser conferidos (o a voltou ao ar).
        $this->up('a.acme.com.br');
        $this->travel(8)->days();
        $this->assertSame(1, $this->discover()['novos']);
    }

    public function test_a_zone_that_did_not_answer_is_tried_again_after_a_week(): void
    {
        $this->crtsh = ['x.app.acme.com.br'];
        $this->up('x.app.acme.com.br');
        $this->dns->failing['app.acme.com.br'] = 1;

        // O NS do nível de cima não respondeu: sugestão por zona desconhecida.
        $this->discover();
        $this->assertSame(['suggested', 'zona_desconhecida', 'passive'], $this->subdomains()['x.app.acme.com.br']);

        // No dia seguinte, ainda não; uma semana depois, avaliada de novo, e o DNS responde: ativo.
        $this->travel(1)->days();
        $this->assertSame(0, $this->discover()['novos']);
        $this->travel(7)->days();
        $this->assertSame(1, $this->discover()['ativos']);
        $this->assertSame(['active', null, 'passive'], $this->subdomains()['x.app.acme.com.br']);
    }

    public function test_the_check_of_the_active_ones_is_inside_the_time_budget(): void
    {
        config(['secmon.subdomains.budget_seconds' => -1]);
        $old = $this->domain->subdomains()->forceCreate(['name' => 'a.acme.com.br', 'origin' => 'passive', 'status' => 'active', 'first_seen_at' => now()->subMonth(), 'last_seen_at' => now()->subMonth()]);
        $this->crtsh = ['b.acme.com.br'];
        $this->up('b.acme.com.br');

        // Sem tempo: nem a reconferência nem os nomes novos andam; a execução fecha com o que tem.
        $this->assertSame(0, $this->discover()['novos']);
        $this->assertTrue($old->refresh()->last_seen_at->lt(now()->subDays(20)));
        $this->assertNotNull($this->domain->refresh()->subdomains_checked_at);
    }
}
