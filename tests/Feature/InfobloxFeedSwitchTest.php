<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Source;
use App\Services\Crawler\CrawlerService;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** O /blog/feed/ da Infoblox deu 404: a fonte passa ao índice JSON do blog, sem despejar o arquivo nem duplicar. */
class InfobloxFeedSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'https://www.infoblox.com/blog/feed/';

    private const NEW = 'https://www.infoblox.com/blog-index.json#/blog/threat-intelligence/';

    private function migration(): object
    {
        return require database_path('migrations/2026_12_06_000300_switch_infoblox_to_blog_index.php');
    }

    private function source(string $feed, array $extra = []): Source
    {
        return Source::create($extra + ['name' => 'Infoblox Threat Intel', 'host' => 'infoblox.com', 'feed_url' => $feed, 'weight' => 6]);
    }

    public function test_the_dead_feed_becomes_the_blog_index_and_the_cut_restarts(): void
    {
        $source = $this->source(self::OLD, ['enabled' => false, 'interval_minutes' => 120]);
        $source->forceFill(['created_at' => now()->subMonth()])->save();

        $this->migration()->up();

        $source->refresh();
        $this->assertSame(self::NEW, $source->feed_url);
        $this->assertTrue($source->feed_changed_at->isToday());
        // O resto fica como o admin deixou.
        $this->assertFalse($source->enabled);
        $this->assertSame(120, $source->interval_minutes);
    }

    public function test_a_feed_the_admin_changed_or_an_index_already_registered_stays(): void
    {
        $custom = $this->source('https://www.infoblox.com/outro/feed/');
        $this->migration()->up();
        $this->assertSame('https://www.infoblox.com/outro/feed/', $custom->refresh()->feed_url);
        $this->assertNull($custom->feed_changed_at);

        Source::query()->delete();
        $old = $this->source(self::OLD);
        $new = $this->source(self::NEW, ['host' => 'infoblox.com']);
        $this->migration()->up();
        $this->assertSame([self::OLD, self::NEW], [$old->refresh()->feed_url, $new->refresh()->feed_url]);
    }

    public function test_the_migration_goes_back_and_the_seeder_does_not_duplicate_the_source(): void
    {
        $source = $this->source(self::OLD);

        $this->migration()->up();
        (new SourceSeeder)->run();
        $this->assertSame([self::NEW], Source::query()->where('host', 'infoblox.com')->pluck('feed_url')->all());

        $this->migration()->down();
        $source->refresh();
        $this->assertSame([self::OLD, null], [$source->feed_url, $source->feed_changed_at]);
    }

    public function test_the_crawl_brings_only_the_recent_threat_intel_posts_once(): void
    {
        Queue::fake();
        $this->travelTo('2026-11-01 12:00:00');
        Http::fake([
            'www.infoblox.com/blog-index.json' => Http::response(file_get_contents(base_path('tests/fixtures/feeds/blog-index.json')), 200, ['Content-Type' => 'application/json']),
            // Cada página com o seu texto: o mesmo texto seria duplicata pelo conteúdo.
            'www.infoblox.com/blog/*' => fn ($request) => Http::response('<html><body><article><p>'.str_repeat("Texto de {$request->url()}. ", 40).'</p></article></body></html>'),
        ]);
        $source = $this->source(self::OLD);
        $source->forceFill(['created_at' => now()->subYear()])->save();
        $this->migration()->up();
        $crawler = app(CrawlerService::class);

        $first = $crawler->runSource($source->refresh());
        $second = $crawler->runSource($source->refresh());

        // O post de 29/10 entra; o de agosto (arquivo) e o da empresa, não. O
        // sem data passa pelo corte e entra como os sem data dos feeds RSS.
        $this->assertSame([
            'https://www.infoblox.com/blog/threat-intelligence/2026/10/the-ultra-trail-from-viewer-to-mailer',
            'https://www.infoblox.com/blog/threat-intelligence/sem-data',
        ], Article::query()->orderBy('id')->pluck('url')->all());
        $this->assertSame(['ok', 2, 2], [$first->status, $first->items_found, $first->items_new]);
        $this->assertSame([0, 2], [$second->items_new, $second->items_duplicate]);
    }
}
