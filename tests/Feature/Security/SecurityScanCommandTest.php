<?php

namespace Tests\Feature\Security;

use App\Jobs\ScanSecuritySite;
use App\Models\SecurityCheck;
use App\Models\SecuritySite;
use App\Models\User;
use App\Services\Security\ScanRecorder;
use App\Services\Security\SiteScanner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Feature\Pentest\PentestRunFixtures;
use Tests\TestCase;

/** secmon:security-scan, o job, o agendamento e o worker próprio da fila `security`. */
class SecurityScanCommandTest extends TestCase
{
    use PentestRunFixtures, RefreshDatabase, SecurityTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSecurity();
    }

    public function test_due_queues_only_enabled_sites_whose_time_has_come_on_the_security_queue(): void
    {
        Queue::fake();
        $never = $this->site(['host' => 'nunca.com.br', 'next_check_at' => null]);
        $due = $this->site(['host' => 'vencido.com.br', 'next_check_at' => now()->subMinute()]);
        $this->site(['host' => 'futuro.com.br', 'next_check_at' => now()->addHour()]);
        $this->site(['host' => 'pausado.com.br', 'enabled' => false, 'next_check_at' => now()->subDay()]);

        $this->artisan('secmon:security-scan', ['--due' => true])->expectsOutput('2 site(s) na fila de segurança.')->assertExitCode(0);

        Queue::assertPushed(ScanSecuritySite::class, 2);
        Queue::assertPushedOn('security', ScanSecuritySite::class, fn ($job) => $job->siteId === $never->id);
        Queue::assertPushedOn('security', ScanSecuritySite::class, fn ($job) => $job->siteId === $due->id);
    }

    public function test_one_host_without_sync_goes_to_the_queue_and_an_unknown_one_is_refused(): void
    {
        Queue::fake();
        $site = $this->site();

        $this->artisan('secmon:security-scan', ['host' => 'https://ACME.com.br/'])->expectsOutput('acme.com.br na fila de segurança.')->assertExitCode(0);
        Queue::assertPushedOn('security', ScanSecuritySite::class, fn ($job) => $job->siteId === $site->id);

        $this->artisan('secmon:security-scan', ['host' => 'nao-cadastrado.com.br'])->expectsOutput('Site não cadastrado em Admin → Segurança.')->assertExitCode(1);
        $this->artisan('secmon:security-scan', ['host' => '127.0.0.1'])->expectsOutput('Site não cadastrado em Admin → Segurança.')->assertExitCode(1);
        $this->artisan('secmon:security-scan')->expectsOutput('Informe o site ou use --due.')->assertExitCode(1);
    }

    public function test_sync_runs_here_records_the_scan_and_prints_the_table(): void
    {
        $this->goodDns();
        $this->fakeTls();
        $this->fakeWeb();
        $site = $this->site();

        $this->artisan('secmon:security-scan', ['host' => 'acme.com.br', '--sync' => true])
            ->expectsOutputToContain('Certificado confiável')
            ->expectsOutputToContain('acme.com.br: ok, nota 100/100.')
            ->assertExitCode(0);

        $this->assertSame(1, SecurityCheck::query()->count());
        $this->assertSame(100, $site->fresh()->last_score);
    }

    public function test_the_job_scans_and_records_and_skips_a_site_that_was_paused_or_deleted(): void
    {
        $this->goodDns();
        $this->fakeTls();
        $this->fakeWeb();
        $site = $this->site();

        (new ScanSecuritySite($site->id))->handle(app(SiteScanner::class), app(ScanRecorder::class));
        $this->assertSame(1, SecurityCheck::query()->count());

        $site->forceFill(['enabled' => false])->save();
        (new ScanSecuritySite($site->id))->handle(app(SiteScanner::class), app(ScanRecorder::class));
        (new ScanSecuritySite(999999))->handle(app(SiteScanner::class), app(ScanRecorder::class));
        $this->assertSame(1, SecurityCheck::query()->count(), 'pausado ou apagado: nada é verificado');
    }

    public function test_when_the_scanner_blows_up_the_failure_is_recorded_and_the_next_scan_is_scheduled(): void
    {
        $site = $this->site(['next_check_at' => now()->subHour()]);
        $this->mock(SiteScanner::class, fn (MockInterface $m) => $m->shouldReceive('scan')->andThrow(new \RuntimeException('a rede caiu')));

        (new ScanSecuritySite($site->id))->handle(app(SiteScanner::class), app(ScanRecorder::class));

        $check = SecurityCheck::query()->sole();
        $this->assertSame(['erro', 'a rede caiu'], [$check->status, $check->error]);
        $this->assertTrue($site->fresh()->next_check_at->isFuture(), 'não fica em loop de nova tentativa a cada 10 minutos');
    }

    public function test_the_job_never_retries_and_has_its_own_queue_and_a_bounded_time(): void
    {
        $job = new ScanSecuritySite(1);

        $this->assertSame('security', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertLessThanOrEqual(180, $job->timeout);
    }

    public function test_the_scheduler_looks_for_due_sites_every_ten_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'secmon:security-scan'));

        $this->assertNotNull($event, 'secmon:security-scan não está no agendador');
        $this->assertStringContainsString('--due', $event->command);
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_a_scan_waits_an_hour_while_a_security_test_runs_on_the_site(): void
    {
        // T10: um teste de segurança no site: a monitoria não lê o site agora, e isso não conta como falha.
        Http::preventStrayRequests();
        $site = $this->site(['next_check_at' => now()->subMinute()]);
        $this->activeRun(User::factory()->create(), $site->host);

        (new ScanSecuritySite($site->id))->handle(app(SiteScanner::class), app(ScanRecorder::class));

        $this->assertSame(0, SecurityCheck::query()->count());
        $this->assertEqualsWithDelta(now()->addHour()->getTimestamp(), $site->refresh()->next_check_at->getTimestamp(), 2);
        Http::assertNothingSent();
    }

    public function test_the_supervisor_has_a_dedicated_worker_for_the_security_queue(): void
    {
        $conf = file_get_contents(base_path('docker/supervisor/secmon.conf'));

        $this->assertStringContainsString('[program:fila-seguranca]', $conf);
        $this->assertMatchesRegularExpression('/queue:work --queue=security\b.*--timeout=180/', $conf);

        // Nenhuma outra fila atende `security`: rede lenta de um site não atrasa alerta nem análise.
        $others = preg_replace('/\[program:fila-seguranca\].*?(?=\n\[program:|\z)/s', '', $conf);
        $this->assertDoesNotMatchRegularExpression('/--queue=[^\s]*\bsecurity\b/', $others);
    }

    public function test_a_row_with_an_invalid_host_never_becomes_a_request(): void
    {
        $this->fakeHttp(['*' => Http::response('x')]);
        $tls = $this->fakeTls();

        foreach (['acme.com.br:9200', 'acme.com.br/../x'] as $host) {
            $site = SecuritySite::query()->create([
                'user_id' => $this->admin()->id, 'host' => $host, 'consent_at' => now(), 'verify_token' => 'x', 'next_check_at' => now(),
            ]);

            $report = app(SiteScanner::class)->scan($site);

            $this->assertSame('erro', $report->status, $host);
            $this->assertStringContainsString('endereço do site inválido', (string) $report->error);
        }

        Http::assertNothingSent();
        $this->assertSame([], $tls->asked);
    }
}
