<?php

namespace Tests\Feature\Alerts;

use App\Jobs\DiscoverDomainStack;
use App\Models\MonitoredDomain;
use App\Services\Alerts\Discovery\DiscoveryService;
use App\Services\Alerts\ProfileIndex;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/** secmon:alerts-discover, o comando do operador: um domínio, a fila diária (--due) e o diagnóstico (--probe). */
class DiscoverCommandTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
    }

    private function domain(array $attributes = []): MonitoredDomain
    {
        return $this->profile(null, [], ['nginx'])->domains()->create($attributes + [
            'domain' => 'acmepay.com.br',
            'verify_token' => 'abc123',
            'consent_at' => now(),
            'consent_version' => 'v1',
            'verified_at' => now(),
        ]);
    }

    public function test_without_arguments_it_explains_what_to_pass(): void
    {
        $this->artisan('secmon:alerts-discover')
            ->expectsOutput('Informe um domínio, --due ou --probe=host.')
            ->assertExitCode(2);
    }

    public function test_a_domain_that_is_not_registered_or_lacks_consent_or_proof_is_refused(): void
    {
        $this->domain(['domain' => 'sem-posse.com.br', 'verified_at' => null]);
        $this->domain(['domain' => 'sem-aceite.com.br', 'consent_at' => null]);
        $this->mock(DiscoveryService::class)->shouldNotReceive('run');

        foreach (['nao-existe.com.br', 'sem-posse.com.br', 'sem-aceite.com.br'] as $name) {
            $this->artisan('secmon:alerts-discover', ['domain' => $name])
                ->expectsOutput('Domínio não cadastrado, sem aceite ou sem posse comprovada.')
                ->assertExitCode(1);
        }
    }

    public function test_a_domain_scanned_inside_the_month_waits_unless_the_operator_forces_it(): void
    {
        $domain = $this->domain(['last_scan_at' => now()->subDays(3)]);
        $this->mock(DiscoveryService::class, function (MockInterface $mock) {
            $mock->shouldReceive('run')->once()->withArgs(fn ($d, bool $force) => $force === false)->andReturn(false);
        });

        $this->artisan('secmon:alerts-discover', ['domain' => $domain->domain])
            ->expectsOutputToContain('(1 por mês). Para testar mesmo assim: --force.')
            ->assertExitCode(0);
    }

    public function test_force_runs_the_scan_and_reports_the_result(): void
    {
        $domain = $this->domain(['discovery_status' => 'done']);
        $this->mock(DiscoveryService::class, function (MockInterface $mock) {
            $mock->shouldReceive('run')->once()->withArgs(fn ($d, bool $force) => $force === true)->andReturn(true);
        });

        $this->artisan('secmon:alerts-discover', ['domain' => $domain->domain, '--force' => true])
            ->expectsOutputToContain('acmepay.com.br: done, 0 achado(s).')
            ->assertExitCode(0);
    }

    public function test_due_does_nothing_while_discovery_is_switched_off(): void
    {
        Queue::fake();
        $this->domain();
        Settings::setMany(['alerts.discovery_enabled' => false]);

        $this->artisan('secmon:alerts-discover', ['--due' => true])
            ->expectsOutput('Descoberta desligada no admin.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_due_queues_only_the_verified_consented_domains_whose_time_has_come(): void
    {
        Queue::fake();
        $due = $this->domain(['domain' => 'vence.com.br', 'last_scan_at' => now()->subDays(45)]);
        $never = $this->domain(['domain' => 'nunca.com.br']);
        $this->domain(['domain' => 'recente.com.br', 'last_scan_at' => now()->subDays(5)]);
        $this->domain(['domain' => 'sem-posse.com.br', 'verified_at' => null]);
        $this->domain(['domain' => 'sem-aceite.com.br', 'consent_at' => null]);
        $this->domain(['domain' => 'agendado.com.br', 'next_run_at' => now()->addDay()]);

        $this->artisan('secmon:alerts-discover', ['--due' => true])
            ->expectsOutput('2 domínio(s) enfileirado(s).')
            ->assertExitCode(0);

        Queue::assertPushed(DiscoverDomainStack::class, 2);
        Queue::assertPushed(DiscoverDomainStack::class, fn ($job) => $job->domainId === $due->id);
        Queue::assertPushed(DiscoverDomainStack::class, fn ($job) => $job->domainId === $never->id);
    }

    public function test_probe_refuses_an_invalid_host_and_prints_what_it_finds_for_a_valid_one(): void
    {
        $this->artisan('secmon:alerts-discover', ['--probe' => 'isto não é host'])
            ->expectsOutput('Host inválido.')
            ->assertExitCode(2);

        $this->mock(DiscoveryService::class, function (MockInterface $mock) {
            $mock->shouldReceive('probe')->once()->with('acmepay.com.br')->andReturn([
                'log' => [['level' => 'ok', 'msg' => 'acmepay.com.br → 104.21.1.1 (público)']],
                'findings' => [['label' => 'Nginx', 'evidence' => 'cabeçalho Server', 'confidence' => 'alta']],
                'new' => [],
                'hints' => ['Tente também o www'],
            ]);
        });

        $this->artisan('secmon:alerts-discover', ['--probe' => 'AcmePay.com.br'])
            ->expectsOutputToContain('[ok] acmepay.com.br → 104.21.1.1 (público)')
            ->expectsOutputToContain('Dica: Tente também o www')
            ->assertExitCode(0);
    }
}
