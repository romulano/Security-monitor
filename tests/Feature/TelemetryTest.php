<?php

namespace Tests\Feature;

use App\Models\CrawlRun;
use App\Models\Source;
use App\Services\Telemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Alerts\AlertsTestHelpers;
use Tests\TestCase;

/** A faixa de telemetria da /sobre: lida do banco no máximo uma vez por minuto. */
class TelemetryTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    private function source(): Source
    {
        return Source::create(['name' => 'Feed', 'host' => 'feed.test', 'feed_url' => 'https://feed.test/rss']);
    }

    public function test_the_counts_and_the_last_sweep_come_from_the_database(): void
    {
        $source = $this->source();
        $this->newsCase('Caso A');
        $this->newsCase('Caso B');
        CrawlRun::create(['source_id' => $source->id, 'trigger' => 'schedule', 'status' => 'ok', 'started_at' => now()->subMinutes(20)]);

        $snapshot = app(Telemetry::class)->snapshot();

        $this->assertSame(1, $snapshot['sources']);
        $this->assertSame(2, $snapshot['cases']);
        $this->assertTrue($snapshot['online']);
        $this->assertEqualsWithDelta(now()->subMinutes(20)->getTimestamp(), $snapshot['lastSweep']->getTimestamp(), 2);
    }

    public function test_the_second_read_inside_the_minute_does_not_touch_the_database(): void
    {
        $this->source();
        app(Telemetry::class)->snapshot();

        DB::enableQueryLog();
        $again = app(Telemetry::class)->snapshot();

        $this->assertSame([], DB::getQueryLog(), 'a segunda leitura veio do cache');
        $this->assertSame(1, $again['sources']);
    }

    public function test_after_a_minute_the_numbers_are_read_again(): void
    {
        $this->source();
        $this->assertSame(1, app(Telemetry::class)->snapshot()['sources']);

        Source::create(['name' => 'Outra', 'host' => 'outra.test', 'feed_url' => 'https://outra.test/rss']);
        $this->assertSame(1, app(Telemetry::class)->snapshot()['sources'], 'ainda dentro do minuto');

        $this->travel(61)->seconds();

        $this->assertSame(2, app(Telemetry::class)->snapshot()['sources']);
    }

    public function test_online_follows_the_clock_even_while_the_counts_are_cached(): void
    {
        $source = $this->source();
        CrawlRun::create(['source_id' => $source->id, 'trigger' => 'schedule', 'status' => 'ok', 'started_at' => now()->subMinutes(119)->subSeconds(30)]);

        $this->assertTrue(app(Telemetry::class)->snapshot()['online'], 'faltam 30 s para as 2 h sem coleta');

        // 45 s depois o cache (1 min) ainda vale, mas a última coleta já passou de 2 h.
        $this->travel(45)->seconds();

        DB::enableQueryLog();
        $this->assertFalse(app(Telemetry::class)->snapshot()['online']);
        $this->assertSame([], DB::getQueryLog(), 'veio do cache, e mesmo assim o sinal caiu');
    }

    public function test_a_database_without_sweeps_is_offline(): void
    {
        $snapshot = app(Telemetry::class)->snapshot();

        $this->assertNull($snapshot['lastSweep']);
        $this->assertFalse($snapshot['online']);
    }
}
