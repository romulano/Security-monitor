<?php

namespace Tests\Feature\Company;

use App\Models\Company;
use App\Models\LookalikeWatch;
use App\Models\OwnedDomain;
use App\Models\OwnedSubdomain;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Lookalike\RadarSubdomains;
use App\Services\Lookalike\VariantGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** Red Team da E4b (2bf9223): só `evidencia_*`, o que resistiu. */
class RedTeamE4bTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private Company $acme;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(DnsResolver::class, new FakeDnsResolver);
        config(['secmon.lookalikes.subdomains_enabled' => true]);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
    }

    private function radar(string $domain, ?Sector $sector): LookalikeWatch
    {
        $watch = $this->watchOf($this->ana, $this->acme, $sector);
        $watch->forceFill(['status' => 'active', 'completed_at' => now(), 'step_reached' => 3])->save();
        $watch->domain->forceFill(['domain' => $domain, 'verified_at' => now()])->save();

        return $watch->refresh();
    }

    private function owned(string $domain, ?Sector $sector, array $subs, int $age = 100): OwnedDomain
    {
        $owned = OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'sector_id' => $sector?->id, 'domain' => $domain,
            'source' => 'company', 'proof_token' => $this->acme->domain_token, 'verified_at' => now()]);

        foreach ($subs as $i => $name) {
            OwnedSubdomain::query()->create(['owned_domain_id' => $owned->id, 'name' => $name, 'origin' => 'manual', 'status' => 'active',
                'facts' => [], 'first_seen_at' => now(), 'activated_at' => now()->subDays($age - $i)]);
        }

        return $owned;
    }

    public function test_evidencia_seeds_never_cross_sectors_and_the_ceiling_takes_the_oldest(): void
    {
        $ti = $this->companySector($this->acme, 'TI');
        $rh = $this->companySector($this->acme, 'RH');
        // O domínio do RH, forçado no banco, e um Radar do TI com o mesmo nome: nenhuma semente do RH entra no Radar do TI.
        $this->owned('rh.com.br', $rh, ['portal-rh.rh.com.br']);
        $this->assertTrue(app(RadarSubdomains::class)->seeds($this->radar('rh.com.br', $ti))['seeds']->isEmpty());

        // Teto de 2 na empresa toda, com dois Radares: os mais antigos primeiro; os genéricos nem contam.
        $this->acme->forceFill(['limit_radar_seeds' => 2])->save();
        $this->owned('acme.com.br', null, ['login.acme.com.br', 'www.acme.com.br', 'pix.acme.com.br']);
        $this->owned('loja.com.br', null, ['app.loja.com.br'], 50);
        $acme = app(RadarSubdomains::class)->seeds($this->radar('acme.com.br', null));
        $loja = app(RadarSubdomains::class)->seeds($this->radar('loja.com.br', null));
        $this->assertSame(['login.acme.com.br', 'pix.acme.com.br'], $acme['seeds']->pluck('name')->all());
        $this->assertSame([[], 1], [$loja['seeds']->pluck('name')->all(), $loja['left_out']]);

        // Desligada a chave, nada.
        config(['secmon.lookalikes.subdomains_enabled' => false]);
        $this->assertTrue(app(RadarSubdomains::class)->seeds($this->radar('acme.com.br', null))['seeds']->isEmpty());
    }

    public function test_evidencia_the_compact_variants_are_valid_hosts_only(): void
    {
        $gen = app(VariantGenerator::class);
        $this->assertCount(40, $gen->generateForSubdomain('login.acme.com.br', 'acme.com.br'));
        $this->assertCount(50, $gen->generateForSubdomain('login.app.acme.com.br', 'acme.com.br'));
        $this->assertSame([], $gen->generateForSubdomain('www.acme.com.br', 'acme.com.br'));
        $this->assertSame([], $gen->generateForSubdomain('login.outra.com.br', 'acme.com.br'));

        // Um rótulo de 63 letras não gera nome acima de 63 por rótulo; nada fora do formato de host.
        $hosts = array_keys($gen->generateForSubdomain(str_repeat('a', 63).'.acme.com.br', 'acme.com.br'));
        $this->assertSame([], array_filter($hosts, fn (string $h) => ! preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}(\.[a-z]{2,})?$/', $h)));
        $this->assertSame([], array_filter($hosts, fn (string $h) => strlen(explode('.', $h)[0]) > 63));
        $this->assertNotContains('acme.com.br', array_keys($gen->generateForSubdomain('login.acme.com.br', 'acme.com.br', ['acme.com.br'])));
    }
}
