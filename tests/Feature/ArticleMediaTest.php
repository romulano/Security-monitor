<?php

namespace Tests\Feature;

use App\Jobs\ReadArticleMedia;
use App\Jobs\RebuildCase;
use App\Models\Article;
use App\Models\User;
use App\Services\Alerts\Discovery\SafeFetcher;
use App\Services\Crawler\ArticleExtractor;
use App\Services\Crawler\CaseWriter;
use App\Services\Enrichment\ArticleMediaReader;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Search\CaseIndex;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ArticleMediaTest extends TestCase
{
    use RefreshDatabase;

    private const PARAGRAPH = 'BeyondTrust disclosed that attackers chained a Remote Support zero-day with stolen API keys to reach customer instances. ';

    protected function setUp(): void
    {
        parent::setUp();

        // O Elasticsearch não faz parte da suíte.
        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
        Storage::fake('local');
    }

    /** PNG de 1×1 pixel de verdade (a imagem do PHP não tem GD para o fake()->image()). */
    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function article(): Article
    {
        $article = Article::create([
            'url' => 'https://www.darkreading.com/endpoint-security/beyondtrust',
            'url_hash' => hash('sha256', 'beyondtrust'),
            'source_host' => 'darkreading.com',
            'original_title' => 'BeyondTrust zero-day exploited',
            'content' => 'Now BeyondTrust joins the group.',
            'content_chars' => 32,
            'extraction' => 'summary',
            'collected_at' => now(),
            'status' => 'pending',
        ]);

        app(CaseWriter::class)->createCase($article, new EnrichmentResult(
            title: 'BeyondTrust zero-day exploited', summary: 'Thin analysis.', criticity: 'critical', driver: 'hermes',
        ));

        return $article->refresh();
    }

    private function html(bool $withImages = true): string
    {
        // Parágrafos distintos: o extrator descarta os repetidos.
        $body = implode('', array_map(fn ($i) => "<p>Part {$i}. ".self::PARAGRAPH.'</p>', range(1, 4)));
        $images = $withImages
            ? '<figure><img src="/images/attack-chain.png" width="900" height="500" alt="Attack chain"></figure>'
              .'<img src="data:image/png;base64,AAAA" width="600"><img src="https://cdn.example.com/icon-share.svg">'
            : '';

        return '<html><head><meta property="og:image" content="//cdn.example.com/hero.jpg"></head><body>'
            .'<header><img src="https://www.darkreading.com/logo.png" width="300" height="200"></header>'
            .'<article class="article-content">'.$body.$images.'</article>'
            .'<aside class="related"><img src="https://www.darkreading.com/other-story.jpg" width="400" height="300"></aside>'
            .'</body></html>';
    }

    public function test_html_gives_the_text_and_only_the_images_of_the_post_body(): void
    {
        $parsed = app(ArticleExtractor::class)->fromHtml($this->html(), 'https://www.darkreading.com/endpoint-security/beyondtrust', withImages: true);

        $this->assertStringContainsString('stolen API keys', $parsed['text']);
        $this->assertSame([
            'https://cdn.example.com/hero.jpg',
            'https://www.darkreading.com/images/attack-chain.png',
        ], $parsed['images']);
    }

    public function test_html_without_images_is_applied_right_away(): void
    {
        $article = $this->article();
        Queue::fake();

        $this->actingAs($this->admin())
            ->put(route('admin.articles.text.update', $article), ['content' => str_replace('<meta property="og:image" content="//cdn.example.com/hero.jpg">', '', $this->html(withImages: false))])
            ->assertRedirect(route('admin.collections', ['extraction' => 'manual']));

        $article->refresh();
        $this->assertSame('manual', $article->extraction);
        $this->assertStringContainsString('stolen API keys', $article->content);
        $this->assertStringNotContainsString('<p>', $article->content);
        Queue::assertPushed(RebuildCase::class);
        Queue::assertNotPushed(ReadArticleMedia::class);
    }

    public function test_html_with_images_goes_to_the_ai_reader(): void
    {
        $article = $this->article();
        Queue::fake();

        $this->actingAs($this->admin())
            ->put(route('admin.articles.text.update', $article), ['content' => $this->html(), 'format' => 'auto'])
            ->assertRedirect(route('admin.articles.text', $article));

        $article->refresh();
        $this->assertSame('summary', $article->extraction, 'o texto só muda depois que a IA ler');
        $this->assertSame('processing', $article->manual_media['state']);
        $this->assertTrue($article->manual_media['from_html']);
        $this->assertCount(2, $article->manual_media['images']);
        $this->assertStringContainsString('stolen API keys', $article->manual_media['text']);
        Queue::assertPushedOn('enrich', ReadArticleMedia::class);
        Queue::assertNotPushed(RebuildCase::class);
    }

    public function test_prints_are_stored_and_sent_to_the_ai_reader(): void
    {
        $article = $this->article();
        Queue::fake();

        $this->actingAs($this->admin())
            ->put(route('admin.articles.text.update', $article), [
                'prints' => [$this->png('topo.png'), $this->png('fim.png')],
            ])
            ->assertRedirect(route('admin.articles.text', $article));

        $media = $article->refresh()->manual_media;
        $this->assertCount(2, $media['prints']);
        $this->assertStringEndsWith('print-01.png', $media['prints'][0]);
        Storage::disk('local')->assertExists($media['prints']);
        Queue::assertPushed(ReadArticleMedia::class);
    }

    public function test_with_the_reader_turned_off_only_the_pasted_text_counts(): void
    {
        Settings::set('prompts.midia', false);
        $article = $this->article();
        Queue::fake();
        $text = implode("\n\n", array_map(fn ($i) => "Part {$i}. ".self::PARAGRAPH, range(1, 4)));

        $this->actingAs($this->admin())
            ->put(route('admin.articles.text.update', $article), ['content' => $text, 'format' => 'text', 'prints' => [$this->png('topo.png')]])
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'A leitura de prints e imagens está desligada em Prompts: só o texto foi usado.'));

        Queue::assertNotPushed(ReadArticleMedia::class);
        $this->assertNull($article->refresh()->manual_media);

        // Só prints: não sobra texto para usar.
        $this->actingAs($this->admin())
            ->put(route('admin.articles.text.update', $article), ['prints' => [$this->png('topo.png')]])
            ->assertSessionHasErrors(['content' => 'A leitura de prints e imagens está desligada (Admin › Prompts). Cole o texto completo da matéria.']);
    }

    public function test_nothing_sent_is_refused(): void
    {
        $article = $this->article();

        $this->actingAs($this->admin())
            ->put(route('admin.articles.text.update', $article), ['content' => ''])
            ->assertSessionHasErrors('content');

        $this->assertNull($article->refresh()->manual_media);
    }

    public function test_non_image_upload_is_refused(): void
    {
        $article = $this->article();

        $this->actingAs($this->admin())
            ->put(route('admin.articles.text.update', $article), ['prints' => [UploadedFile::fake()->create('malware.exe', 10)]])
            ->assertSessionHasErrors('prints.0');
    }

    private function processing(Article $article, array $prints = [], array $images = []): void
    {
        $article->forceFill(['manual_media' => [
            'state' => 'processing', 'dir' => 'article-media/'.$article->id.'/x', 'text' => 'Texto colado parcial.',
            'from_html' => $images !== [], 'prints' => $prints,
            'images' => array_map(fn ($u) => ['url' => $u, 'path' => null, 'error' => null], $images), 'figures' => [],
        ]])->save();
    }

    public function test_job_writes_the_text_with_only_the_figures_of_the_post(): void
    {
        $article = $this->article();
        Storage::disk('local')->put('article-media/p1.png', $this->png('p1.png')->getContent());
        $this->processing($article, ['article-media/p1.png'], ['https://cdn.example.com/chart.png', 'https://cdn.example.com/page.html']);

        $this->mock(SafeFetcher::class, function ($mock) {
            $mock->shouldReceive('startScan')->once();
            $mock->shouldReceive('get')->andReturnUsing(fn (string $url) => [
                'ok' => true, 'status' => 200, 'url' => $url, 'bytes' => 4, 'body' => 'PNG!', 'truncated' => false, 'error' => null,
                'headers' => ['content-type' => [str_ends_with($url, '.png') ? 'image/png' : 'text/html; charset=utf-8']],
            ]);
        });

        $this->mock(ArticleMediaReader::class)->shouldReceive('read')->once()
            ->withArgs(fn ($a, $text, $prints, $figures) => $text === 'Texto colado parcial.' && count($prints) === 1 && count($figures) === 1)
            ->andReturn([
                'text' => str_repeat(self::PARAGRAPH, 3),
                'figures' => [
                    ['source' => 'figure', 'ref' => 1, 'part_of_post' => true, 'reason' => 'gráfico do ataque', 'description' => 'Linha do tempo com o IP 203.0.113.7.'],
                    ['source' => 'print', 'ref' => 1, 'part_of_post' => false, 'reason' => 'logo do site', 'description' => ''],
                ],
            ]);

        Queue::fake();
        app()->call([new ReadArticleMedia($article->id), 'handle']);

        $article->refresh();
        $this->assertSame('manual', $article->extraction);
        $this->assertStringContainsString('stolen API keys', $article->content);
        $this->assertStringContainsString('Figura (imagem 1 do HTML): Linha do tempo com o IP 203.0.113.7.', $article->content);
        $this->assertStringNotContainsString('logo do site', $article->content);
        $this->assertSame('done', $article->manual_media['state']);
        $this->assertSame('não é imagem (text/html)', $article->manual_media['images'][1]['error']);
        Storage::disk('local')->assertExists($article->manual_media['images'][0]['path']);
        Queue::assertPushed(RebuildCase::class);

        $this->actingAs($this->admin())->get(route('admin.articles.text', $article))
            ->assertOk()
            ->assertSee('faz parte do post')
            ->assertSee('descartada')
            ->assertSee('Linha do tempo com o IP 203.0.113.7.');
    }

    public function test_job_failure_keeps_the_old_text(): void
    {
        $article = $this->article();
        Storage::disk('local')->put('article-media/p1.png', 'PNG!');
        $this->processing($article, ['article-media/p1.png']);

        $this->mock(SafeFetcher::class)->shouldIgnoreMissing();
        $this->mock(ArticleMediaReader::class)->shouldReceive('read')->andThrow(new RuntimeException('hermes HTTP 503: fora do ar'));

        Queue::fake();
        app()->call([new ReadArticleMedia($article->id), 'handle']);

        $article->refresh();
        $this->assertSame('summary', $article->extraction);
        $this->assertSame('Now BeyondTrust joins the group.', $article->content);
        $this->assertSame('failed', $article->manual_media['state']);
        $this->assertStringContainsString('fora do ar', $article->error);
        Queue::assertNotPushed(RebuildCase::class);
    }

    public function test_reader_sends_the_images_to_hermes_and_reads_a_fenced_answer(): void
    {
        config(['secmon.enricher.hermes.url' => 'http://hermes.test', 'secmon.enricher.hermes.token' => 'tok']);
        Http::fake(['hermes.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => "```json\n".json_encode([
            'text' => 'Article text.',
            'figures' => [['source' => 'print', 'ref' => 2, 'part_of_post' => 'true', 'reason' => 'print de código', 'description' => 'curl http://evil.test']],
        ])."\n```"]]]])]);

        $result = app(ArticleMediaReader::class)->read(
            $this->article(), 'colado', [['mime' => 'image/png', 'data' => 'PNG!']], [['mime' => 'image/jpeg', 'data' => 'JPG!', 'url' => 'https://cdn.example.com/a.jpg']],
        );

        $this->assertSame('Article text.', $result['text']);
        $this->assertTrue($result['figures'][0]['part_of_post']);
        $this->assertSame(2, $result['figures'][0]['ref']);

        Http::assertSent(function (Request $request) {
            $parts = $request['messages'][1]['content'];
            $urls = collect($parts)->where('type', 'image_url')->pluck('image_url.url')->all();

            return $request->hasHeader('Authorization', 'Bearer tok')
                && $urls === ['data:image/png;base64,'.base64_encode('PNG!'), 'data:image/jpeg;base64,'.base64_encode('JPG!')]
                && str_contains($parts[0]['text'], 'You received 1 print(s) and 1 figure(s).');
        });
    }

    public function test_compose_keeps_the_figures_when_the_text_hits_the_limit(): void
    {
        config(['secmon.crawler.max_content_chars' => 500]);

        $content = ReadArticleMedia::compose([
            'text' => str_repeat('a', 2000),
            'figures' => [['source' => 'figure', 'ref' => 1, 'part_of_post' => true, 'reason' => '', 'description' => 'IOC 198.51.100.4']],
        ]);

        $this->assertLessThanOrEqual(500, mb_strlen($content));
        $this->assertStringEndsWith('IOC 198.51.100.4', $content);
    }
}
