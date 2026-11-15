<?php

namespace Tests\Feature;

use App\Models\Source;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** As 5 fontes de 2026-11-01 chegam a quem já tem fontes, sem mexer no que o admin mudou. */
class FiveNewsSourcesTest extends TestCase
{
    use RefreshDatabase;

    private const FEEDS = [
        'https://www.helpnetsecurity.com/feed/',
        'https://hackread.com/feed/',
        'https://www.malwarebytes.com/blog/feed/index.xml',
        'https://www.cybersecuritydive.com/feeds/news/',
        'https://securitylabs.datadoghq.com/rss/feed.xml',
    ];

    private function migration(): object
    {
        return require database_path('migrations/2026_11_29_000100_add_five_news_sources.php');
    }

    public function test_the_five_sources_arrive_enabled_and_hourly(): void
    {
        Source::create(['name' => 'Krebs', 'host' => 'krebsonsecurity.com', 'feed_url' => 'https://krebsonsecurity.com/feed/', 'weight' => 8]);

        $this->migration()->up();

        $new = Source::query()->whereIn('feed_url', self::FEEDS)->get();
        $this->assertCount(5, $new);
        $this->assertTrue($new->every(fn (Source $s) => $s->enabled && $s->interval_minutes === 60));
    }

    public function test_a_source_the_admin_turned_off_stays_off(): void
    {
        $source = Source::create(['name' => 'HR', 'host' => 'hackread.com', 'feed_url' => 'https://hackread.com/feed/', 'weight' => 2, 'enabled' => false]);

        $this->migration()->up();

        $source->refresh();
        $this->assertSame(['HR', 2, false], [$source->name, $source->weight, $source->enabled]);
        $this->assertSame(1, Source::query()->where('host', 'hackread.com')->count());
    }

    public function test_a_new_install_waits_for_the_seeder_and_the_migration_goes_back(): void
    {
        $migration = $this->migration();

        $migration->up();
        $this->assertSame(0, Source::query()->count());

        Source::create(['name' => 'Krebs', 'host' => 'krebsonsecurity.com', 'feed_url' => 'https://krebsonsecurity.com/feed/', 'weight' => 8]);
        $migration->up();
        $migration->down();

        $this->assertSame(0, Source::query()->whereIn('feed_url', self::FEEDS)->count());
        $this->assertSame(count(SourceSeeder::SOURCES) - 5, Source::query()->count());
    }
}
