<?php

namespace Tests\Feature\Company;

use App\Models\Company;
use App\Models\OwnedDomain;
use App\Models\OwnedSubdomain;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Domains\SubdomainCheck;
use App\Services\Domains\SubdomainDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * Red Team da E3c. `rt_*`: passava no e92eebf e o patch fecha; `evidencia_*`: o que resistiu.
 *
 * - N1 (§6.3: "o reconferir não pode ativar por curinga"): com um curinga no DNS da empresa (`*.acme.com.br`), todo nome
 *   que já teve certificado resolve pelo curinga, mesmo sem registro próprio, e entra **ativo**: os nomes mortos dos
 *   certificados antigos ocupam as 20 vagas e viram alvo do Radar (E4a) e semente (E4b). O nome que só resolve pelo
 *   curinga (o IP é o de um rótulo aleatório no mesmo nível) vira sugestão com o motivo `curinga`.
 * - N2: na reconferência diária, o NS mudo de um nível acima (ou de um salto do CNAME, hopZone) vira `zona_desconhecida`,
 *   que é `outside`: o ativo passivo desce a sugestão por um timeout e não volta sozinho (a sugestão fica sugestão).
 */
class RedTeamE3cTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private FakeDnsResolver $dns;

    private Company $acme;

    private User $ana;

    private OwnedDomain $domain;

    /** @var list<string> */
    private array $crtsh = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(DnsResolver::class, $this->dns = new FakeDnsResolver);
        Http::fake(['crt.sh/*' => fn () => Http::response(array_map(fn (string $name) => ['name_value' => $name], $this->crtsh))]);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->domain = OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'domain' => 'acme.com.br',
            'source' => 'company', 'proof_token' => $this->acme->domain_token, 'verified_at' => now()->subMonth()]);
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    private function subdomains(): array
    {
        return OwnedSubdomain::query()->orderBy('name')->get()->mapWithKeys(fn (OwnedSubdomain $s) => [$s->name => [$s->status, $s->reason]])->all();
    }

    public function test_rt_n1_a_name_that_only_resolves_through_a_wildcard_is_a_suggestion(): void
    {
        $this->dns->wildcard('acme.com.br', 'ips', ['203.0.113.80']);
        $this->dns->set('loja.acme.com.br', 'ips', ['203.0.113.10']);
        // Um nome com registro próprio que por acaso dá o mesmo IP do curinga também é só do curinga para o DNS: sugestão.
        $this->crtsh = ['promo-2019.acme.com.br', 'loja.acme.com.br'];

        app(SubdomainDiscovery::class)->run($this->domain);

        // No e92eebf: promo-2019 ativo (só existe pelo curinga).
        $this->assertSame(['loja.acme.com.br' => ['active', null], 'promo-2019.acme.com.br' => ['suggested', 'curinga']], $this->subdomains());
    }

    public function test_rt_n2_a_mute_ns_answer_never_demotes_an_active_name(): void
    {
        $this->dns->set('app.loja.acme.com.br', 'ips', ['203.0.113.10']);
        $check = app(SubdomainCheck::class)->check('acme.com.br', 'app.loja.acme.com.br');
        $sub = OwnedSubdomain::query()->create(['owned_domain_id' => $this->domain->id, 'name' => 'app.loja.acme.com.br', 'origin' => 'passive', 'status' => 'active',
            'facts' => $check['facts'], 'first_seen_at' => now(), 'activated_at' => now(), 'last_seen_at' => now()]);

        $this->dns->failing['loja.acme.com.br'] = 1;
        app(SubdomainDiscovery::class)->run($this->domain);

        // No e92eebf: vira sugestão com zona_desconhecida.
        $this->assertSame(['active', null], [$sub->refresh()->status, $sub->reason]);
    }

    public function test_evidencia_hostile_certificate_names_never_land(): void
    {
        $this->dns->set('ok.acme.com.br', 'ips', ['203.0.113.10']);
        $long = str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 50).'.acme.com.br';
        $this->crtsh = ["ok.acme.com.br\n*.x.acme.com.br", 'xacme.com.br', 'acme.com.br.evil.com', 'lojá.acme.com.br', $long,
            "inj\u{0000}.acme.com.br", 'a_b.acme.com.br', 'acme.com.br', '-x.acme.com.br', "x.acme.com.br\r\nX-Log: 1"];

        app(SubdomainDiscovery::class)->run($this->domain);

        $this->assertSame(['ok.acme.com.br'], array_keys($this->subdomains()));
    }
}
