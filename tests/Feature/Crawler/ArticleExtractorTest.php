<?php

namespace Tests\Feature\Crawler;

use App\Services\Crawler\ArticleExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A página da matéria: de onde sai o texto, o que é ruído e o que o crawler recusa. */
class ArticleExtractorTest extends TestCase
{
    use RefreshDatabase;

    private function extractor(): ArticleExtractor
    {
        return new ArticleExtractor;
    }

    private function paragraph(string $word, int $times = 6): string
    {
        return '<p>'.trim(str_repeat("A equipe de resposta analisou o incidente {$word} com cuidado. ", $times)).'</p>';
    }

    public function test_extract_downloads_the_page_with_the_crawler_identity_and_reads_it(): void
    {
        Http::fake(['site.test/*' => Http::response('<html><body><article>'.$this->paragraph('x', 12).'</article></body></html>')]);

        $result = $this->extractor()->extract('https://site.test/a');

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
        $this->assertStringContainsString('incidente x', $result['text']);
        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent') && $request->header('Accept-Language')[0] === 'en-US,en;q=0.9');
    }

    public function test_extract_reports_the_http_status_and_connection_errors(): void
    {
        Http::fake(['bloqueado.test/*' => Http::response('Forbidden', 403)]);
        $this->assertSame('HTTP 403', $this->extractor()->extract('https://bloqueado.test/a')['error']);

        Http::fake(['fora.test/*' => fn () => throw new ConnectionException('sem rota')]);
        $result = $this->extractor()->extract('https://fora.test/a');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sem rota', $result['error']);
    }

    public function test_an_empty_body_and_a_too_short_text_are_not_ok(): void
    {
        $this->assertSame('corpo vazio', $this->extractor()->fromHtml('   ')['error']);

        $short = $this->extractor()->fromHtml('<html><body><article><p>Só uma frase curta de teste aqui.</p></article></body></html>');
        $this->assertFalse($short['ok']);
        $this->assertSame('texto principal muito curto', $short['error']);
    }

    public function test_the_json_ld_article_body_wins_over_the_text_of_the_page(): void
    {
        $body = str_repeat('Texto limpo do artigo estruturado. ', 20);
        $html = '<html><head><script type="application/ld+json">'.json_encode(['@graph' => [['@type' => 'NewsArticle', 'articleBody' => $body]]]).'</script></head>'
            .'<body><article>'.$this->paragraph('da-pagina', 10).'</article></body></html>';

        $result = $this->extractor()->fromHtml($html);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Texto limpo do artigo estruturado.', $result['text']);
        $this->assertStringNotContainsString('da-pagina', $result['text']);
    }

    public function test_a_broken_json_ld_is_ignored_and_the_page_text_is_used(): void
    {
        $html = '<html><head><script type="application/ld+json">{ não é json</script></head><body><article>'.$this->paragraph('pagina', 12).'</article></body></html>';

        $this->assertStringContainsString('incidente pagina', $this->extractor()->fromHtml($html)['text']);
    }

    public function test_menus_footers_scripts_and_related_blocks_stay_out_of_the_text(): void
    {
        $html = '<html><body><nav><p>Menu principal do site com vários links úteis</p></nav>'
            .'<div class="entry-content">'.$this->paragraph('corpo', 10)
            .'<div class="related-posts"><p>Leia também: outra matéria muito interessante do blog</p></div>'
            .'<script>var segredo = "NAO-DEVE-APARECER";</script></div>'
            .'<footer><p>Rodapé com direitos reservados e política de privacidade</p></footer></body></html>';

        $text = $this->extractor()->fromHtml($html)['text'];

        $this->assertStringContainsString('incidente corpo', $text);
        $this->assertStringNotContainsString('Menu principal', $text);
        $this->assertStringNotContainsString('Leia também', $text);
        $this->assertStringNotContainsString('NAO-DEVE-APARECER', $text);
        $this->assertStringNotContainsString('Rodapé', $text);
    }

    public function test_without_a_known_container_the_densest_block_of_the_page_is_read(): void
    {
        $html = '<html><body><div><p>Aviso curto de cookies do site para o leitor.</p></div>'
            .'<div class="miolo">'.$this->paragraph('denso', 8).$this->paragraph('denso-dois', 8).'</div></body></html>';

        $result = $this->extractor()->fromHtml($html);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('incidente denso-dois', $result['text']);
    }

    public function test_repeated_paragraphs_are_kept_once(): void
    {
        $repeated = '<p>Parágrafo único que a página repete duas vezes seguidas.</p>';
        $html = '<html><body><article>'.$repeated.$repeated.$this->paragraph('resto', 10).'</article></body></html>';

        $text = $this->extractor()->fromHtml($html)['text'];

        $this->assertSame(1, substr_count($text, 'Parágrafo único que a página repete'));
    }

    public function test_the_text_is_cut_at_the_configured_limit(): void
    {
        config(['secmon.crawler.max_content_chars' => 500]);
        $html = '<html><body><article>'.$this->paragraph('longo', 40).'</article></body></html>';

        $result = $this->extractor()->fromHtml($html);

        $this->assertSame(500, mb_strlen($result['text']));
        $this->assertTrue($result['ok']);
    }

    public function test_the_page_metadata_comes_from_the_head(): void
    {
        $html = '<html><head><meta property="og:title" content="Título da matéria"><meta property="og:description" content="Descrição curta">'
            .'<meta name="author" content="Ana Souza"><meta property="article:published_time" content="2026-10-20T15:00:00-03:00"></head>'
            .'<body><article>'.$this->paragraph('meta', 12).'</article></body></html>';

        $result = $this->extractor()->fromHtml($html);

        $this->assertSame('Título da matéria', $result['title']);
        $this->assertSame('Descrição curta', $result['description']);
        $this->assertSame('Ana Souza', $result['author']);
        $this->assertSame('2026-10-20 18:00:00', $result['published_at']->format('Y-m-d H:i:s'));
    }

    public function test_an_unparseable_publish_date_is_just_missing(): void
    {
        $html = '<html><head><meta name="date" content="semana passada"></head><body><article>'.$this->paragraph('d', 12).'</article></body></html>';

        $this->assertNull($this->extractor()->fromHtml($html)['published_at']);
    }

    public function test_images_come_back_absolute_without_logos_icons_svg_or_tiny_pictures(): void
    {
        $html = '<html><head><meta property="og:image" content="/social/capa.jpg"></head><body><article>'.$this->paragraph('img', 12)
            .'<img src="/img/foto-grande.png" width="800">'
            .'<img src="/img/logo-do-site.png">'
            .'<img src="/img/pequena.png" width="40" height="40">'
            .'<img src="/img/vetor.svg">'
            .'<img src="data:image/gif;base64,R0lGOD">'
            .'<img data-src="//cdn.test/lazy.webp">'
            .'<img srcset="a-320.jpg 320w, a-1200.jpg 1200w, a-640.jpg 640w" src="a-320.jpg">'
            .'</article></body></html>';

        $images = $this->extractor()->fromHtml($html, 'https://site.test/blog/post', withImages: true)['images'];

        $this->assertSame([
            'https://site.test/social/capa.jpg',
            'https://site.test/img/foto-grande.png',
            'https://cdn.test/lazy.webp',
            'https://site.test/blog/a-1200.jpg',
        ], $images);
    }

    public function test_at_most_eight_images_are_returned(): void
    {
        $imgs = implode('', array_map(fn ($n) => "<img src=\"/img/foto-{$n}.jpg\">", range(1, 12)));
        $html = '<html><body><article>'.$this->paragraph('muitas', 12).$imgs.'</article></body></html>';

        $this->assertCount(8, $this->extractor()->fromHtml($html, 'https://site.test/p', withImages: true)['images']);
    }

    public function test_without_the_images_flag_the_result_has_no_images_key(): void
    {
        $result = $this->extractor()->fromHtml('<html><body><article>'.$this->paragraph('z', 12).'</article></body></html>');

        $this->assertArrayNotHasKey('images', $result);
    }

    public function test_absolute_url_resolves_every_form_of_src(): void
    {
        $base = 'https://site.test:8443/blog/post.html';

        $this->assertSame('http://x.test/a.png', ArticleExtractor::absoluteUrl($base, 'http://x.test/a.png'));
        $this->assertSame('https://cdn.test/a.png', ArticleExtractor::absoluteUrl($base, '//cdn.test/a.png'));
        $this->assertSame('https://site.test:8443/a.png', ArticleExtractor::absoluteUrl($base, '/a.png'));
        $this->assertSame('https://site.test:8443/blog/a.png', ArticleExtractor::absoluteUrl($base, 'a.png'));
        $this->assertNull(ArticleExtractor::absoluteUrl(null, 'a.png'));
        $this->assertNull(ArticleExtractor::absoluteUrl('sem-host', 'a.png'));
    }
}
