<?php

namespace Tests\Feature\Crawler;

use App\Models\CrawlRun;
use App\Models\Source;
use App\Services\Crawler\CrawlerHttp;
use App\Services\Crawler\CrawlerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O proxy por fonte: só a coleta da fonte que tem proxy sai por ele (o feed e
 * as páginas das matérias), e a senha dele nunca vai para a execução, a fonte
 * ou o log.
 */
class CrawlerHttpTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY = 'socks5h://a:b@1.2.3.4:9611';

    /** Registra o proxy de cada requisição montada, sem mudar o que ela faz. */
    private function recorder(): CrawlerHttp
    {
        $http = new class extends CrawlerHttp
        {
            /** @var list<?string> */
            public array $proxies = [];

            public function request(?string $proxy = null): PendingRequest
            {
                $this->proxies[] = $proxy;

                return parent::request($proxy);
            }
        };

        $this->app->instance(CrawlerHttp::class, $http);

        return $http;
    }

    private function source(?string $proxy): Source
    {
        return Source::create(['name' => 'Feed', 'host' => 'feed.test', 'feed_url' => 'https://feed.test/rss', 'proxy_url' => $proxy]);
    }

    private function fakeFeed(): void
    {
        Http::fake([
            'feed.test/rss' => Http::response('<?xml version="1.0"?><rss version="2.0"><channel><title>F</title><item><title>A</title>'
                .'<link>https://feed.test/a</link><pubDate>'.now()->toRfc2822String().'</pubDate></item></channel></rss>'),
            'feed.test/*' => Http::response('<html><body><article><p>'.str_repeat('Texto da matéria. ', 60).'</p></article></body></html>'),
        ]);
    }

    public function test_without_a_proxy_the_request_goes_out_directly(): void
    {
        $this->assertArrayNotHasKey('proxy', (new CrawlerHttp)->request()->getOptions());
        $this->assertArrayNotHasKey('proxy', (new CrawlerHttp)->request('')->getOptions());
    }

    public function test_with_a_proxy_the_request_goes_through_it(): void
    {
        $this->assertSame(self::PROXY, (new CrawlerHttp)->request(self::PROXY)->getOptions()['proxy']);
    }

    public function test_redact_takes_the_user_and_password_out_of_any_url(): void
    {
        $this->assertSame('socks5h://***@1.2.3.4:9611', CrawlerHttp::redact(self::PROXY));
        $this->assertSame(
            'cURL error 97: falha no proxy socks5h://***@1.2.3.4:9611 para https://feed.test/rss',
            CrawlerHttp::redact('cURL error 97: falha no proxy '.self::PROXY.' para https://feed.test/rss'),
        );
        $this->assertSame('HTTP 403', CrawlerHttp::redact('HTTP 403'));
    }

    public function test_a_source_with_a_proxy_fetches_the_feed_and_the_article_through_it(): void
    {
        Queue::fake();
        $this->fakeFeed();
        $http = $this->recorder();

        $run = app(CrawlerService::class)->runSource($this->source(self::PROXY));

        $this->assertSame([1, 1], [$run->items_found, $run->items_new]);
        // Uma vez para o feed, outra para a página da matéria.
        $this->assertSame([self::PROXY, self::PROXY], $http->proxies);
    }

    public function test_a_source_without_a_proxy_passes_null(): void
    {
        Queue::fake();
        $this->fakeFeed();
        $http = $this->recorder();

        app(CrawlerService::class)->runSource($this->source(null));

        $this->assertSame([null, null], $http->proxies);
    }

    public function test_a_proxy_error_is_saved_and_logged_without_the_password(): void
    {
        Log::spy();
        Http::fake(fn () => throw new ConnectionException('cURL error 97: Could not connect to '.self::PROXY));
        $source = $this->source(self::PROXY);

        $run = app(CrawlerService::class)->runSource($source);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('socks5h://***@1.2.3.4:9611', $run->message);
        $this->assertStringNotContainsString('a:b@', $run->message);
        $this->assertStringNotContainsString('a:b@', (string) $source->refresh()->last_error);
        $this->assertStringNotContainsString('a:b@', (string) CrawlRun::query()->value('message'));
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => ! str_contains($context['error'], 'a:b@'))->once();
    }
}
