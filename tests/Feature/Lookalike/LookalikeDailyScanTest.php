<?php

namespace Tests\Feature\Lookalike;

use App\Jobs\ScanLookalikes;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Lookalike\LookalikeScanner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\TestCase;

/** O Radar varre todo dia à 01:00 (Brasília), não mais uma vez por semana. */
class LookalikeDailyScanTest extends TestCase
{
    use LookalikeTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTempBrowserDir();
        $this->app->instance(DnsResolver::class, new FakeDnsResolver);
        Http::fake(['*' => Http::response('', 404)]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBrowserDir();
        parent::tearDown();
    }

    private function nextScan(): string
    {
        return LookalikeScanner::nextScanAt()->timezone('America/Sao_Paulo')->format('Y-m-d H:i');
    }

    public function test_the_next_scan_is_the_next_one_in_the_morning_of_brasilia(): void
    {
        // 09:00 em Brasília: a próxima 01:00 é a de amanhã.
        $this->travelTo(Carbon::parse('2026-10-23 12:00:00', 'UTC'));
        $this->assertSame('2026-10-24 01:00', $this->nextScan());
        $this->assertSame('2026-10-24 04:00:00', LookalikeScanner::nextScanAt()->toDateTimeString(), 'guardada no fuso do banco (UTC)');

        // 00:00 em Brasília: ainda dá tempo da de hoje.
        $this->travelTo(Carbon::parse('2026-10-23 03:00:00', 'UTC'));
        $this->assertSame('2026-10-23 01:00', $this->nextScan());

        // Em cima da hora, conta a de amanhã: quem acabou de varrer não repete.
        $this->travelTo(Carbon::parse('2026-10-23 04:00:00', 'UTC'));
        $this->assertSame('2026-10-24 01:00', $this->nextScan());

        // Passou da 01:00 (varredura longa que terminou às 01:40).
        $this->travelTo(Carbon::parse('2026-10-23 04:40:00', 'UTC'));
        $this->assertSame('2026-10-24 01:00', $this->nextScan());
    }

    public function test_the_hour_comes_from_the_config(): void
    {
        config(['secmon.lookalikes.scan_at' => '02:30']);
        $this->travelTo(Carbon::parse('2026-10-23 12:00:00', 'UTC'));

        $this->assertSame('2026-10-24 02:30', $this->nextScan());
    }

    public function test_a_finished_scan_schedules_the_next_one_for_01_00(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 04:20:00', 'UTC'));
        $watch = $this->watch(attributes: ['next_scan_at' => now()->subMinutes(20), 'scan_status' => 'pending']);

        $this->artisan('secmon:lookalikes-scan', ['domain' => $watch->domain->domain, '--sync' => true])->assertSuccessful();

        $this->assertSame('2026-10-24 04:00:00', $watch->refresh()->next_scan_at->toDateTimeString());
    }

    public function test_every_active_radar_is_scanned_when_it_is_01_00_and_only_then(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-23 04:00:00', 'UTC'));
        $watch = $this->watch(attributes: ['next_scan_at' => LookalikeScanner::nextScanAt()]);
        // A releitura de 20 h não existe mais: nem com a última leitura de ontem de manhã.
        $watch->update(['stats' => ['rechecked_at' => now()->subHours(30)->toIso8601String()]]);

        $this->travelTo(Carbon::parse('2026-10-24 03:59:00', 'UTC'));
        $this->artisan('secmon:lookalikes-scan --due')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->travelTo(Carbon::parse('2026-10-24 04:00:30', 'UTC'));
        $this->artisan('secmon:lookalikes-scan --due')->assertSuccessful();
        Queue::assertPushed(ScanLookalikes::class, 1);
        Queue::assertPushed(ScanLookalikes::class, fn (ScanLookalikes $job) => $job->watchId === $watch->id && $job->full);
    }

    public function test_the_scheduler_still_looks_for_due_radars_every_15_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'secmon:lookalikes-scan --due'));

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertNull(collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'lookalikes-scan --recheck')));
    }

    public function test_the_panel_tells_the_next_scan_with_date_and_hour(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 12:00:00', 'UTC'));
        $domain = $this->verifiedDomain();
        $this->watch($domain, ['next_scan_at' => LookalikeScanner::nextScanAt()]);

        $this->actingAs($domain->user)
            ->get(route('alerts.lookalikes'))
            ->assertOk()
            ->assertSee('Próxima em 24/10 às 01:00')
            ->assertSee('varre todo dia à 01:00');
    }
}
