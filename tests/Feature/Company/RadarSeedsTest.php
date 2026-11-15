<?php

namespace Tests\Feature\Company;

use App\Models\Company;
use App\Models\LookalikeWatch;
use App\Models\OwnedDomain;
use App\Models\OwnedSubdomain;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Lookalike\BrandContext;
use App\Services\Lookalike\LookalikeScanner;
use App\Services\Lookalike\RadarSubdomains;
use App\Services\Lookalike\RiskScorer;
use App\Services\Lookalike\VariantGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * E4b (DESENHO-5.3 §5.3, P8): os subdomínios vigiados viram sementes de imitação compactas (~40 nomes cada, agrupados
 * por terminação), nos mesmos lotes da varredura, até o teto de 300 sementes da empresa (só subdomínios; entram os mais
 * antigos). DNS sempre pelo dublê: nada sai para a rede.
 */
class RadarSeedsTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private FakeDnsResolver $dns;

    private Company $acme;

    private User $ana;

    private LookalikeWatch $watch;

    private OwnedDomain $owned;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->app->instance(DnsResolver::class, $this->dns = new FakeDnsResolver);
        config(['secmon.lookalikes.subdomains_enabled' => true, 'secmon.lookalikes.max_variants' => 20, 'secmon.lookalikes.dns_retry_pause_ms' => 0]);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com.br');
        $this->owned = OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'domain' => 'acme.com.br', 'source' => 'company',
            'proof_token' => $this->acme->fresh()->domain_token, 'verified_at' => now(), 'checked_at' => now(), 'check_status' => 'present']);
        $this->watch = $this->watchOf($this->ana, $this->acme);
        $this->watch->forceFill(['status' => 'active', 'completed_at' => now(), 'step_reached' => 3])->save();
        $this->watch->domain->forceFill(['domain' => 'acme.com.br', 'verified_at' => now()])->save();
    }

    private function sub(string $name, int $daysAgo = 1): OwnedSubdomain
    {
        return OwnedSubdomain::query()->create(['owned_domain_id' => $this->owned->id, 'name' => $name, 'origin' => 'manual', 'status' => 'active',
            'facts' => ['outside' => false, 'ips' => ['200.10.10.10']], 'first_seen_at' => now(), 'activated_at' => now()->subDays($daysAgo)]);
    }

    public function test_a_seed_gives_the_compact_names_grouped_by_suffix_and_a_generic_label_gives_none(): void
    {
        $generator = app(VariantGenerator::class);
        $names = $generator->generateForSubdomain('login.acme.com.br', 'acme.com.br');

        $this->assertCount(40, $names);
        $this->assertSame(['subdominio'], array_values(array_unique($names)));
        $this->assertArrayHasKey('login-acme.com', $names);
        $this->assertArrayHasKey('acmelogin.store', $names);
        $this->assertArrayHasKey('login-app-acme.com.br', $generator->generateForSubdomain('login.app.acme.com.br', 'acme.com.br'));
        $this->assertSame([], $generator->generateForSubdomain('www.acme.com.br', 'acme.com.br'));
        $this->assertSame([], $generator->generateForSubdomain('_dmarc.acme.com.br', 'acme.com.br'));
        foreach (['cdn', 'static', 'api', 'www2'] as $generic) {
            $this->assertSame([], $generator->generateForSubdomain("{$generic}.acme.com.br", 'acme.com.br'), $generic);
        }
        $this->assertSame([], $generator->generateForSubdomain('login.outra.com.br', 'acme.com.br'));

        // Agrupados por terminação: todos os .com antes de todos os .com.br, e assim por diante.
        $this->assertSame(['a-acme.com', 'c-acme.com', 'a-acme.com.br', 'b-acme.net'], VariantGenerator::bySuffix(['b-acme.net', 'a-acme.com.br', 'c-acme.com', 'a-acme.com']));
    }

    public function test_the_full_scan_checks_the_compact_names_and_records_the_found_ones(): void
    {
        Http::fake(['*' => Http::response([])]);
        $this->sub('login.acme.com.br');
        $this->sub('www.acme.com.br');
        $this->dns->set('login-acme.net', 'ns', ['ns1.golpe.example']);

        app(LookalikeScanner::class)->scan($this->watch->refresh());

        $log = collect($this->watch->refresh()->last_log)->pluck('text');
        $this->assertTrue($log->contains(fn ($line) => str_starts_with($line, 'Sementes de subdomínio: 1 (')), $log->implode("\n"));
        $this->assertContains('ns login-acme.net', $this->dns->asked);
        $this->assertNotContains('ns www-acme.net', $this->dns->asked, 'www não é semente');
        $found = $this->watch->lookalikes()->where('host', 'login-acme.net')->sole();
        $this->assertSame(['subdominio'], $found->techniques);
        // A semente fica nos sinais (melhoria do Red Team da E4b), e o RiskScorer a mantém a cada releitura.
        $this->assertContains('Imita login.acme.com.br', array_column($found->signals, 'label'));
        app(RiskScorer::class)->apply($found, BrandContext::for($this->watch));
        $this->assertSame(1, collect($found->signals)->where('key', 'subdomain')->count());
    }

    public function test_the_ceiling_counts_only_subdomains_takes_the_oldest_and_warns_the_company(): void
    {
        $this->acme->forceFill(['limit_radar_seeds' => 2])->save();
        $this->sub('novo.acme.com.br', 1);
        $this->sub('antigo.acme.com.br', 30);
        $this->sub('meio.acme.com.br', 10);
        $this->sub('www.acme.com.br', 60);

        ['seeds' => $seeds, 'used' => $used, 'limit' => $limit, 'left_out' => $out] = app(RadarSubdomains::class)->seeds($this->watch->refresh());

        $this->assertSame(['antigo.acme.com.br', 'meio.acme.com.br'], $seeds->pluck('name')->all());
        $this->assertSame([3, 2, 1], [$used, $limit, $out]);
        $this->assertSame([80, 100], DB::table('company_limit_notices')->where('company_id', $this->acme->id)->where('limit', 'radar_seeds')->orderBy('level')->pluck('level')->all());

        // Sem a chave, nada: nem semente, nem aviso novo.
        config(['secmon.lookalikes.subdomains_enabled' => false]);
        $this->assertSame(0, app(RadarSubdomains::class)->seeds($this->watch->refresh())['seeds']->count());
    }
}
