<?php

namespace Tests\Feature;

use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A CISA (403 da Akamai a todo robô) sai e a NCSC do Reino Unido e a CERT-EU entram. */
class ReplaceCisaSourcesTest extends TestCase
{
    use RefreshDatabase;

    private const FEEDS = [
        'https://www.ncsc.gov.uk/api/1/services/v1/news-rss-feed.xml',
        'https://cert.europa.eu/publications/security-advisories-rss',
    ];

    private function migration(): object
    {
        return require database_path('migrations/2026_12_06_000100_replace_cisa_sources.php');
    }

    private function cisa(): Source
    {
        return Source::create([
            'name' => 'CISA — Alerts', 'host' => 'cisa.gov',
            'feed_url' => 'https://www.cisa.gov/cybersecurity-advisories/alerts.xml', 'weight' => 9,
        ]);
    }

    public function test_cisa_is_turned_off_and_the_two_new_sources_arrive_enabled(): void
    {
        $cisa = $this->cisa();

        $this->migration()->up();

        $this->assertFalse($cisa->refresh()->enabled);
        $new = Source::query()->whereIn('feed_url', self::FEEDS)->get();
        $this->assertCount(2, $new);
        $this->assertTrue($new->every(fn (Source $s) => $s->enabled && $s->interval_minutes === 60));
    }

    public function test_a_new_source_the_admin_already_changed_stays_as_it_is(): void
    {
        $this->cisa();
        $ncsc = Source::create(['name' => 'NCSC', 'host' => 'ncsc.gov.uk', 'feed_url' => self::FEEDS[0], 'weight' => 3, 'enabled' => false]);

        $this->migration()->up();

        $ncsc->refresh();
        $this->assertSame(['NCSC', 3, false], [$ncsc->name, $ncsc->weight, $ncsc->enabled]);
        $this->assertSame(1, Source::query()->where('host', 'ncsc.gov.uk')->count());
    }

    public function test_a_new_install_waits_for_the_seeder_and_the_migration_goes_back(): void
    {
        $migration = $this->migration();

        $migration->up();
        $this->assertSame(0, Source::query()->count());

        $cisa = $this->cisa();
        $migration->up();
        $migration->down();

        $this->assertTrue($cisa->refresh()->enabled);
        $this->assertSame(0, Source::query()->whereIn('feed_url', self::FEEDS)->count());
    }
}
