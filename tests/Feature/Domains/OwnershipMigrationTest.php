<?php

namespace Tests\Feature\Domains;

use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Models\PentestDomain;
use App\Models\SecuritySite;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Domains\DomainOwnership;
use App\Services\Lookalike\RadarInterview;
use App\Services\Pentest\PentestEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Feature\Lookalike\LookalikeTestHelpers;
use Tests\TestCase;

/**
 * A migração da posse para a conta (owned_domains): cada nome de cada serviço entra com todos os
 * tokens que tinha e a prova mais antiga, ninguém troca o TXT, e cada serviço continua comprovado.
 */
class OwnershipMigrationTest extends TestCase
{
    use LookalikeTestHelpers, RefreshDatabase;

    private object $migration;

    private object $company;

    private object $subdomains;

    private object $subdomainAlerts;

    private object $keys;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->app->instance(DnsResolver::class, new FakeDnsResolver);
        $this->migration = require database_path('migrations/2026_11_28_000100_create_owned_domains_table.php');
        // As colunas de empresa da posse (E3a0) sobem junto: o código de hoje as lê.
        $this->company = require database_path('migrations/2026_12_14_000100_add_company_and_sector_to_owned_domains.php');
        // Os subdomínios (E3b) apontam para a posse: descem antes e sobem depois.
        $this->subdomains = require database_path('migrations/2026_12_16_000100_create_owned_subdomains_table.php');
        // E os avisos do Radar sobre eles (E4a) apontam para os subdomínios.
        $this->subdomainAlerts = require database_path('migrations/2026_12_17_000100_create_subdomain_alerts_table.php');
        // As chaves expostas e a varredura delas também apontam para a posse.
        $this->keys = require database_path('migrations/2026_12_20_000100_create_key_sites_and_exposed_keys_tables.php');
        $this->keys->down();
        $this->subdomainAlerts->down();
        $this->subdomains->down();
        // Os dados de antes da unificação: só as linhas de cada serviço.
        $this->migration->down();
    }

    private function row(User $user, string $name, string $token, array $attributes = []): MonitoredDomain
    {
        return MonitoredDomain::query()->create($attributes + ['user_id' => $user->id, 'domain' => $name, 'verify_token' => $token]);
    }

    private function copy(User $user, string $name, string $token, ?string $status, bool $verified = true): PentestDomain
    {
        $copy = $this->row($user, $name, $token, ['verified_at' => $verified ? now()->subDays(2) : null]);

        return PentestDomain::query()->create(['user_id' => $user->id, 'domain_id' => $copy->id, 'host' => $name, 'step_reached' => 2, 'ownership_status' => $status]);
    }

    private function site(User $admin, string $host, string $token, ?string $ownership): SecuritySite
    {
        return SecuritySite::query()->create([
            'user_id' => $admin->id, 'host' => $host, 'consent_at' => now(), 'verify_token' => $token,
            'ownership' => $ownership, 'ownership_checked_at' => now()->subHour(),
        ]);
    }

    private function owned(User $user, string $name): OwnedDomain
    {
        return OwnedDomain::query()->where('user_id', $user->id)->where('domain', $name)->sole();
    }

    public function test_every_proof_and_every_token_of_every_service_comes_along(): void
    {
        $ana = $this->client();
        $admin = $this->adminUser();
        $bia = $this->client();

        // Ana: dois alertas com o mesmo domínio (um comprovado, outro com token próprio), um Radar, a cópia
        // dos testes que herdou a prova, uma cópia cujo TXT sumiu e um domínio ainda sem prova.
        $first = $this->row($ana, 'acme.com.br', 'aaaa1111aaaa1111', ['profile_id' => $this->profile($ana, ['name' => 'Loja virtual'])->id,
            'verified_at' => now()->subDays(10), 'consent_at' => now()->subDays(10), 'consent_version' => 'v3']);
        $this->row($ana, 'acme.com.br', 'bbbb2222bbbb2222', ['profile_id' => $this->profile($ana, ['name' => 'Blog'])->id]);
        $radar = $this->row($ana, 'outra.com.br', 'cccc3333cccc3333', ['verified_at' => now()->subDays(3), 'consent_at' => now(), 'consent_version' => 'radar-v1']);
        $watch = $this->watch($radar);
        $kept = $this->copy($ana, 'acme.com.br', 'aaaa1111aaaa1111', 'present');
        $this->copy($ana, 'nova.com.br', 'dddd4444dddd4444', 'missing');
        $this->row($ana, 'pendente.com.br', 'eeee5555eeee5555', ['profile_id' => $this->profile($ana, ['name' => 'Pendente'])->id]);
        // A cópia da prova de um webhook comprovado.
        $hook = $this->row($ana, 'ganchos.com.br', 'gggg7777gggg7777', ['verified_at' => now()->subDay()]);
        DB::table('notification_channels')->insert(['user_id' => $ana->id, 'type' => 'webhook', 'method' => 'json', 'name' => 'Produção',
            'webhook_url' => 'https://hooks.ganchos.com.br/secmon', 'webhook_host' => 'hooks.ganchos.com.br', 'domain_id' => $hook->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now()]);
        // O admin: um site comprovado pelo TXT e outro só pelo servidor do Security Monitor (que não é TXT).
        $this->site($admin, 'meusite.com.br', str_repeat('s', 24), 'txt');
        $this->site($admin, 'servidor.com.br', str_repeat('t', 24), 'server');
        // Bia: só um domínio, ainda sem prova.
        $this->row($bia, 'bia.com.br', 'ffff6666ffff6666', ['profile_id' => $this->profile($bia)->id]);

        $this->migration->up();
        $this->company->up();
        $this->subdomains->up();
        $this->subdomainAlerts->up();
        $this->keys->up();

        $this->assertSame(8, OwnedDomain::query()->count());
        $this->assertSame(['webhook', 'gggg7777gggg7777'], [$this->owned($ana, 'ganchos.com.br')->source, $this->owned($ana, 'ganchos.com.br')->proof_token]);

        $acme = $this->owned($ana, 'acme.com.br');
        $this->assertSame(['news', 'aaaa1111aaaa1111', true], [$acme->source, $acme->proof_token, $acme->legacy]);
        $this->assertEquals($first->verified_at, $acme->verified_at, 'a prova mais antiga, com a data dela');
        $this->assertSame(['aaaa1111aaaa1111', 'bbbb2222bbbb2222'], $acme->tokens, 'os dois tokens continuam valendo');

        $outra = $this->owned($ana, 'outra.com.br');
        $this->assertSame(['radar', 'cccc3333cccc3333'], [$outra->source, $outra->proof_token]);
        $this->assertTrue($outra->isVerified());

        $this->assertNull($this->owned($ana, 'nova.com.br')->verified_at, 'a cópia que viu o TXT sumir não prova nada');
        $this->assertSame(['dddd4444dddd4444'], $this->owned($ana, 'nova.com.br')->tokens);
        $this->assertNull($this->owned($ana, 'pendente.com.br')->verified_at);

        $site = $this->owned($admin, 'meusite.com.br');
        $this->assertSame(['monitor', str_repeat('s', 24)], [$site->source, $site->proof_token]);
        $this->assertTrue($site->isVerified());
        $this->assertNull($this->owned($admin, 'servidor.com.br')->verified_at, 'a prova "servidor" fica só com o monitor');
        $this->assertSame([str_repeat('t', 24)], $this->owned($admin, 'servidor.com.br')->tokens);

        // O token da conta é o que já está no DNS: o da prova mais antiga; sem prova, o da linha mais nova.
        $this->assertSame('aaaa1111aaaa1111', $ana->fresh()->domain_token);
        $this->assertSame(str_repeat('s', 24), $admin->fresh()->domain_token);
        $this->assertSame('ffff6666ffff6666', $bia->fresh()->domain_token);

        // Cada serviço continua comprovado: o alerta, o Radar ativo, a cópia dos testes e o monitor.
        $this->assertTrue($first->refresh()->isVerified());
        $this->assertSame('active', $watch->refresh()->status);
        $this->assertTrue($kept->refresh()->ownershipHolds());
        $this->assertSame('txt', SecuritySite::query()->where('host', 'meusite.com.br')->value('ownership'));

        // E vale nos outros: o domínio do Radar aparece pronto para os testes, o do alerta para outro Radar.
        $this->assertSame(['outra.com.br', 'ganchos.com.br'], app(PentestEnrollment::class)->provenDomains($ana)->pluck('domain')->all());
        $this->assertSame(['acme.com.br', 'ganchos.com.br'], app(RadarInterview::class)->provenDomains($ana)->pluck('domain')->all());
        $this->assertSame([], app(DomainOwnership::class)->proven($bia)->all());
    }

    public function test_the_migration_is_reversible(): void
    {
        $user = $this->client();
        $this->row($user, 'acme.com.br', 'aaaa1111aaaa1111', ['verified_at' => now()]);

        $this->migration->up();
        $this->company->up();
        $this->subdomains->up();
        $this->subdomainAlerts->up();
        $this->keys->up();
        $this->assertTrue(Schema::hasTable('owned_domains'));
        $this->assertTrue(Schema::hasColumn('users', 'domain_token'));

        $this->keys->down();
        $this->subdomainAlerts->down();
        $this->subdomains->down();
        $this->migration->down();
        $this->assertFalse(Schema::hasTable('owned_domains'));
        $this->assertFalse(Schema::hasColumn('users', 'domain_token'));
        $this->assertTrue(MonitoredDomain::query()->sole()->isVerified(), 'as linhas dos serviços ficam como estavam');

        $this->migration->up();
        $this->company->up();
        $this->subdomains->up();
        $this->subdomainAlerts->up();
        $this->keys->up();
        $this->assertTrue(OwnedDomain::query()->sole()->isVerified());
    }
}
