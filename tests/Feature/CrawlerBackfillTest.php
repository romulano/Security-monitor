<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Source;
use App\Services\Crawler\CrawlerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrawlerBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A análise não faz parte do teste: só importa o que o crawler grava.
        Queue::fake();
    }

    /** @param array<string, Carbon> $items slug => data de publicação */
    private function fakeFeed(array $items): void
    {
        $xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>Feed</title>';

        foreach ($items as $slug => $date) {
            $xml .= "<item><title>Post {$slug}</title><link>https://feed.test/{$slug}</link>"
                ."<pubDate>{$date->toRfc2822String()}</pubDate><description>Resumo {$slug}</description></item>";
        }

        Http::fake([
            'feed.test/rss' => Http::response($xml.'</channel></rss>', 200, ['Content-Type' => 'application/rss+xml']),
            'feed.test/*' => Http::response('<html><body><article><p>'.str_repeat('Texto da matéria. ', 60).'</p></article></body></html>'),
        ]);
    }

    private function source(Carbon $createdAt): Source
    {
        $source = Source::create(['name' => 'Feed', 'host' => 'feed.test', 'feed_url' => 'https://feed.test/rss']);
        $source->forceFill(['created_at' => $createdAt])->save();

        return $source;
    }

    public function test_new_source_leaves_the_feed_archive_out_for_good(): void
    {
        $this->fakeFeed(['recente' => now()->subDays(2), 'antigo' => now()->subYear()]);
        $source = $this->source(now());
        $crawler = app(CrawlerService::class);

        $first = $crawler->runSource($source);
        $second = $crawler->runSource($source->refresh());

        $this->assertSame(['https://feed.test/recente'], Article::query()->pluck('url')->all());
        $this->assertSame([1, 1, 0], [$first->items_found, $first->items_new, $first->items_duplicate]);
        // Na segunda passada o post antigo continua de fora, e não como duplicado.
        $this->assertSame([1, 0, 1], [$second->items_found, $second->items_new, $second->items_duplicate]);
    }

    public function test_source_registered_long_ago_keeps_taking_older_posts(): void
    {
        $this->fakeFeed(['mes-passado' => now()->subDays(20)]);
        $source = $this->source(now()->subYear());

        app(CrawlerService::class)->runSource($source);

        $this->assertSame(['https://feed.test/mes-passado'], Article::query()->pluck('url')->all());
    }

    public function test_an_old_source_that_switched_feeds_leaves_the_new_feed_archive_out(): void
    {
        // O TechCrunch (2026-11-01): fonte de outubro, trocada do feed geral para o de segurança.
        $this->fakeFeed(['recente' => now()->subDays(2), 'mes-passado' => now()->subDays(20)]);
        $source = $this->source(now()->subYear());
        $source->forceFill(['feed_changed_at' => now()])->save();

        $run = app(CrawlerService::class)->runSource($source->refresh());

        $this->assertSame(['https://feed.test/recente'], Article::query()->pluck('url')->all());
        $this->assertSame([1, 1], [$run->items_found, $run->items_new]);
    }
}
