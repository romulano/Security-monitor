<?php

namespace Tests\Feature\Keys;

use App\Exceptions\ActionRefused;
use App\Jobs\ScanSiteKeys;
use App\Models\ExposedKey;
use App\Models\KeySite;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\Discovery\SafeFetcher;
use App\Services\Keys\ArchiveUrls;
use App\Services\Keys\KeyCrawler;
use App\Services\Keys\KeyDetector;
use App\Services\Keys\KeySites;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Feature\Lookalike\LookalikeTestHelpers;
use Tests\TestCase;

/**
 * A varredura de chaves de um domínio comprovado: só leitura no domínio e nos subdomínios dele (o
 * robots.txt, as ações negadas, nada fora do escopo), as chaves no registro do domínio, e a posse
 * como a única autorização (conferida ao pedir e de novo ao rodar). A semanal, o limite do "Varrer
 * agora" e o job preso por um worker morto.
 */
class KeyScanTest extends TestCase
{
    use LookalikeTestHelpers, RefreshDatabase;

    private User $user;

    private FakeDnsResolver $dns;

    private const HTML = ['Content-Type' => 'text/html; charset=utf-8'];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config(['secmon.keys.archive_url' => 'https://archive.test/cdx']);
        $this->app->instance(DnsResolver::class, $this->dns = (new FakeDnsResolver)
            ->set('loja.com.br', 'ips', ['34.1.1.1'])->set('www.loja.com.br', 'ips', ['34.1.1.2'])->set('terceiro.com', 'ips', ['34.9.9.9']));
        $this->user = $this->client();
    }

    public function test_the_scan_reads_only_the_proven_domain_and_records_the_keys(): void
    {
        Http::fake([
            'https://loja.com.br/robots.txt' => Http::response("User-agent: *\nDisallow: /privado\n", 200, ['Content-Type' => 'text/plain']),
            'https://loja.com.br/' => Http::response('<html><head><script src="/assets/app.js"></script><script src="https://terceiro.com/lib.js"></script></head><body>'
                .'<a href="/sobre">sobre</a> <a href="/privado/x">privado</a> <a href="/sair">sair</a> <a href="/conta?_token=abc">token</a>'
                .'<a href="/backup.sql">dump</a> <a href="/logo.png">logo</a> <a href="https://terceiro.com/x">fora</a> <a href="mailto:a@b.com">e-mail</a>'
                .'<a href="https://www.loja.com.br/contato#form">contato</a></body></html>', 200, self::HTML),
            'https://loja.com.br/assets/app.js' => Http::response('const STRIPE_KEY = "pk_live_51HqLyjWDarjtT1zdp7dcXYZabcQ2wE"; var k = "AKIAZ3MSJV4WWNKWW7XQ";', 200,
                ['Content-Type' => 'application/javascript']),
            'https://loja.com.br/sobre' => Http::response('<title>Sobre</title><script>var s = "pk_live_51HqLyjWDarjtT1zdp7dcXYZabcQ2wE";</script>', 200, self::HTML),
            'https://www.loja.com.br/robots.txt' => Http::response('', 404),
            'https://www.loja.com.br/contato' => Http::response('<script>fetch("https://admin:Sup3rS3nha2026@db.interno.lab/x")</script>', 200, self::HTML),
            '*' => Http::response('nada', 404),
        ]);
        $owned = $this->proven('loja.com.br');

        $site = app(KeySites::class)->activate($owned, $this->user);
        $this->assertSame('queued', $site->scan_status);
        $this->runJob($site);

        // Só GET, só o domínio e o subdomínio dele; nada do robots, de ação, de token, de dados, de binário ou de fora.
        $asked = collect(Http::recorded())->reject(fn (array $pair) => str_contains($pair[0]->url(), 'archive.test'))->values()->map(fn (array $pair) => $pair[0]->method().' '.$pair[0]->url())->all();
        // Só o domínio e o subdomínio dele, inclusive o que o robots.txt esconde (a posse é a autorização: o /privado do Disallow
        // vira pista); nunca ação, link com token, dados, binário ou o que é de fora.
        $this->assertEqualsCanonicalizing(['GET https://loja.com.br/robots.txt', 'GET https://loja.com.br/', 'GET https://loja.com.br/sitemap.xml',
            'GET https://loja.com.br/sobre', 'GET https://loja.com.br/assets/app.js', 'GET https://loja.com.br/privado', 'GET https://loja.com.br/privado/x',
            'GET https://www.loja.com.br/robots.txt', 'GET https://www.loja.com.br/contato'], $asked);

        $keys = ExposedKey::query()->where('owned_domain_id', $owned->id)->orderBy('kind')->get()->keyBy('kind');
        $this->assertSame(['aws_access_key', 'password_in_url', 'stripe_publishable'], $keys->keys()->all());
        $this->assertSame([2, 'STRIPE_KEY', 'scan'], [$keys['stripe_publishable']->occurrences, $keys['stripe_publishable']->name, $keys['stripe_publishable']->source]);
        // No mesmo nome, o 1º caminho e quantas vezes (como o mapeamento dos testes); outro nome é outro lugar.
        // Cada lugar com a URL, a linha e a coluna: no HTML da página "sobre" e no script.
        $this->assertEqualsCanonicalizing([
            ['host' => 'loja.com.br', 'path' => '/sobre', 'url' => 'https://loja.com.br/sobre', 'line' => 1, 'col' => 38],
            ['host' => 'loja.com.br', 'path' => '/assets/app.js', 'url' => 'https://loja.com.br/assets/app.js', 'line' => 1, 'col' => 21],
        ], $keys['stripe_publishable']->locations);
        $this->assertSame('www.loja.com.br', $keys['password_in_url']->locations[0]['host']);
        $this->assertStringNotContainsString('Sup3rS3nha2026', ExposedKey::query()->get()->toJson());

        $site->refresh();
        $this->assertSame(['idle', 'ok', null], [$site->scan_status, $site->last_result, $site->last_error]);
        // (Mais o tempo da varredura e o de rede, que não são fixos.)
        $this->assertEquals(['pages' => 3, 'scripts' => 1, 'sitemap_urls' => 0, 'archive_urls' => 0, 'requests' => 9, 'hosts' => 2, 'keys' => 3, 'new' => 3, 'truncated' => false, 'unanswered' => 0],
            array_diff_key($site->last_summary, ['seconds' => 0, 'net_seconds' => 0]));
        $this->assertArrayHasKey('net_seconds', $site->last_summary);
        $this->assertTrue($site->next_scan_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
    }

    public function test_only_a_proven_domain_is_scanned_and_the_proof_is_checked_again_when_the_job_runs(): void
    {
        Queue::fake();
        $unproven = OwnedDomain::query()->forceCreate(['user_id' => $this->user->id, 'domain' => 'semprova.com.br', 'source' => 'radar']);
        $this->assertSame('A posse de semprova.com.br não está comprovada: comprove pelo registro TXT antes de varrer.',
            $this->refused(fn () => app(KeySites::class)->activate($unproven, $this->user)));

        // Comprovado ao pedir; a reconferência perde o TXT antes de o job rodar: nada é pedido ao site.
        Http::fake();
        $owned = $this->proven('loja.com.br');
        $site = app(KeySites::class)->activate($owned, $this->user);
        $owned->forceFill(['unseen_since' => now()])->save();
        $this->runJob($site);

        Http::assertNothingSent();
        $site->refresh();
        $this->assertSame(['idle', 'failed', 'A posse do domínio não está mais comprovada: publique o registro TXT de novo.'], [$site->scan_status, $site->last_result, $site->last_error]);
    }

    public function test_varrer_agora_waits_for_the_last_one_and_for_30_minutes(): void
    {
        Queue::fake();
        $site = app(KeySites::class)->activate($this->proven('loja.com.br'), $this->user);
        Queue::assertPushedOn('keys', ScanSiteKeys::class, fn (ScanSiteKeys $job) => $job->siteId === $site->id);

        $this->assertSame('Já tem uma varredura deste domínio na fila ou rodando.', $this->refused(fn () => app(KeySites::class)->request($site, manual: true)));
        $site->forceFill(['scan_status' => 'idle'])->save();
        $this->travel(10)->minutes();
        $this->assertStringStartsWith('Este domínio foi varrido há pouco: dá para pedir de novo às', $this->refused(fn () => app(KeySites::class)->request($site, manual: true)));
        $this->travel(21)->minutes();
        $this->assertSame('queued', app(KeySites::class)->request($site, manual: true)->scan_status);

        app(KeySites::class)->pause($site->refresh()->forceFill(['scan_status' => 'idle']));
        $this->assertSame('A varredura deste domínio está pausada.', $this->refused(fn () => app(KeySites::class)->request($site->refresh(), manual: true)));
    }

    public function test_the_weekly_scan_takes_the_due_sites_and_resumes_the_interrupted_one(): void
    {
        Queue::fake();
        $due = KeySite::query()->create(['owned_domain_id' => $this->proven('loja.com.br')->id, 'next_scan_at' => now()->subMinute()]);
        $later = KeySite::query()->create(['owned_domain_id' => $this->proven('outra.com.br')->id, 'next_scan_at' => now()->addDay()]);
        $paused = KeySite::query()->create(['owned_domain_id' => $this->proven('pausada.com.br')->id, 'status' => 'paused', 'next_scan_at' => now()->subDay()]);
        // O deploy matou a etapa: sem sinal há 15 min, com o estado do último minuto gravado. Continua dali.
        $killed = KeySite::query()->create(['owned_domain_id' => $this->proven('presa.com.br')->id, 'scan_status' => 'running', 'started_at' => now()->subMinutes(15),
            'scan_state' => ['read' => 40, 'js' => 20], 'next_scan_at' => now()->subDay()]);
        // Interrompida 3 vezes, ou sem estado nenhum: fica com o que achou.
        $tired = KeySite::query()->create(['owned_domain_id' => $this->proven('cansada.com.br')->id, 'scan_status' => 'running', 'started_at' => now()->subMinutes(15),
            'scan_state' => ['read' => 90, 'resumes' => KeySites::MAX_RESUMES]]);
        $alive = KeySite::query()->create(['owned_domain_id' => $this->proven('viva.com.br')->id, 'scan_status' => 'running', 'started_at' => now()->subMinutes(3),
            'scan_state' => ['read' => 5]]);
        $lost = OwnedDomain::query()->forceCreate(['user_id' => $this->user->id, 'domain' => 'perdida.com.br', 'source' => 'radar', 'verified_at' => now(), 'unseen_since' => now()]);
        $unproven = KeySite::query()->create(['owned_domain_id' => $lost->id, 'next_scan_at' => now()->subDay()]);

        $this->artisan('secmon:keys-scan --due')->expectsOutput('1 varredura(s) de chaves pedida(s).')->assertSuccessful();

        Queue::assertPushedOn('keys', ScanSiteKeys::class, fn (ScanSiteKeys $job) => $job->siteId === $due->id && ! $job->continues);
        Queue::assertPushedOn('keys', ScanSiteKeys::class, fn (ScanSiteKeys $job) => $job->siteId === $killed->id && $job->continues);
        Queue::assertPushed(ScanSiteKeys::class, 2);
        $this->assertSame(['running', 1], [$killed->refresh()->scan_status, $killed->scan_state['resumes']]);
        $this->assertTrue($killed->started_at->isAfter(now()->subMinute()));
        $this->assertSame(['idle', null, 'failed', 'A varredura foi interrompida algumas vezes e parou; as chaves achadas até ali ficaram.'],
            [$tired->refresh()->scan_status, $tired->scan_state, $tired->last_result, $tired->last_error]);
        $this->assertSame(['running', 'idle', 'idle'], [$alive->refresh()->scan_status, $later->refresh()->scan_status, $paused->refresh()->scan_status]);
        $this->assertTrue($unproven->refresh()->next_scan_at->isFuture());

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'secmon:keys-scan --due'));
        $this->assertSame('*/5 * * * *', $event->expression);
    }

    public function test_what_is_found_is_recorded_as_it_goes_and_kept_when_the_scan_fails(): void
    {
        // Entregas de 0 s: a cada requisição, o que achou até ali vai para o registro.
        config(['secmon.keys.checkpoint_seconds' => 0]);
        Http::fake([
            'archive.test/*' => Http::response(''),
            'https://loja.com.br/robots.txt' => Http::response('', 404),
            'https://loja.com.br/' => Http::response('<script>var k = "AKIAZ3MSJV4WWNKWW7XQ";</script><a href="/sobre">s</a><a href="/termos">t</a>', 200, self::HTML),
            '*' => Http::response('<title>Página</title>', 200, self::HTML),
        ]);
        $owned = $this->proven('loja.com.br');
        $partials = [];
        app(KeyCrawler::class)->crawl('loja.com.br', [], null, function (array $partial) use (&$partials) {
            $partials[] = $partial;
        });

        $this->assertGreaterThanOrEqual(2, count($partials));
        $this->assertSame('aws_access_key', $partials[0]['keys']['loja.com.br'][0]['kind']);
        $this->assertIsArray($partials[0]['state']);

        // Uma etapa que cai no meio (aqui, uma exceção depois da 1ª entrega): a chave entregue fica no registro.
        $this->app->bind(KeyCrawler::class, fn () => new class(app(SafeFetcher::class), new KeyDetector, app(ArchiveUrls::class)) extends KeyCrawler
        {
            public function crawl(string $domain, array $seeds = [], ?array $state = null, ?callable $checkpoint = null): array
            {
                $checkpoint(['keys' => ['loja.com.br' => [['kind' => 'aws_access_key', 'masked' => 'AKIA••••••••W7XQ (20 caracteres)', 'name' => 'k',
                    'path' => '/', 'fp' => str_repeat('ab', 16), 'count' => 1]]], 'state' => ['read' => 1]]);

                throw new \RuntimeException('o servidor caiu');
            }
        });
        $site = app(KeySites::class)->activate($owned, $this->user);
        $this->runJob($site);

        $this->assertSame('aws_access_key', ExposedKey::query()->where('owned_domain_id', $owned->id)->sole()->kind);
        $this->assertSame(['idle', 'failed', 'A varredura falhou no meio; as chaves achadas até ali ficaram, e ela vai de novo na próxima vez.'],
            [$site->refresh()->scan_status, $site->last_result, $site->last_error]);
    }

    public function test_the_robots_file_does_not_bar_the_authorized_scan_and_a_silent_site_fails(): void
    {
        Http::fake([
            'https://loja.com.br/robots.txt' => Http::response('erro', 503),
            'https://loja.com.br/' => Http::response('<a href="/sobre">sobre</a><script>var k = "AKIAZ3MSJV4WWNKWW7XQ";</script>', 200, self::HTML),
            '*' => Http::response('nada', 404),
        ]);
        $site = app(KeySites::class)->activate($this->proven('loja.com.br'), $this->user);
        $this->runJob($site);

        // O robots.txt ilegível (503) não barra nada: a varredura autorizada segue para a página do link.
        $this->assertContains('https://loja.com.br/sobre', collect(Http::recorded())->map(fn (array $pair) => $pair[0]->url())->all());
        $this->assertSame(['ok', 1], [$site->refresh()->last_result, $site->last_summary['keys']]);

        // Sem resposta nenhuma (nem no http): falhou, sem chave.
        Http::fake(['*' => fn (Request $request) => throw new ConnectionException('sem rota')]);
        $other = app(KeySites::class)->activate($this->proven('www.loja.com.br'), $this->user);
        $this->runJob($other);
        $this->assertSame(['failed', 'O site de www.loja.com.br não respondeu.'], [$other->refresh()->last_result, $other->last_error]);
    }

    public function test_the_recon_goes_beyond_the_home_scripts_sitemaps_and_imported_chunks(): void
    {
        // A home de um site de JS moderno: 40 pedaços de script antes do 1º link (o que gastava a navegação inteira).
        $preload = implode('', array_map(fn (int $i) => '<link rel="modulepreload" href="/build/c'.$i.'.js">', range(1, 40)));
        $chunk = fn (string $code = 'export const a=1;') => Http::response($code, 200, ['Content-Type' => 'text/javascript']);
        $routes = [
            'https://loja.com.br/robots.txt' => Http::response("User-agent: *\nDisallow: /privado\nSitemap: https://loja.com.br/shared/sitemap.xml\n"),
            'https://loja.com.br/' => Http::response('<html><head>'.$preload.'<link rel="preload" as="image" href="/img/hero.avif"><link rel="preload" as="font" href="/f.woff2">'
                .'<script type="module" src="/build/entry.js"></script></head><body>'
                .'<a href="/categoria-sp">SP</a><a href="/categoria-rj">RJ</a></body></html>', 200, self::HTML),
            'https://loja.com.br/shared/sitemap.xml' => Http::response('<?xml version="1.0"?><sitemapindex><sitemap><loc>https://loja.com.br/shared/perfis.xml</loc></sitemap></sitemapindex>'),
            'https://loja.com.br/shared/perfis.xml' => Http::response('<?xml version="1.0"?><urlset>'.implode('', array_map(fn (int $i) => "<url><loc>https://loja.com.br/perfil/moca-{$i}</loc></url>", range(1, 10)))
                .'<url><loc>https://terceiro.com/x</loc></url></urlset>'),
            // O entry importa um pedaço que só ele cita (com a chave) e cita caminhos: uma página e uma API.
            'https://loja.com.br/build/entry.js' => $chunk('import{a}from"./lazy-Ab12.js";const r=["/fale-conosco","/api/v1/usuarios","/v2/x"];'),
            'https://loja.com.br/build/lazy-Ab12.js' => $chunk("// pedaço carregado sob demanda\nconst cfg={\n  stripe:\"sk_test_51HqLyjWDarjtT1zdp7dcXYZabcQ2wEr\"};"),
            'https://loja.com.br/categoria-*' => Http::response('<title>Categoria</title><a href="/perfil/moca-99">perfil</a>', 200, self::HTML),
            'https://loja.com.br/perfil/*' => Http::response('<title>Perfil</title>', 200, self::HTML),
            'https://loja.com.br/fale-conosco' => Http::response('<title>Contato</title>', 200, self::HTML),
        ];

        foreach (range(1, 40) as $i) {
            $routes["https://loja.com.br/build/c{$i}.js"] = $chunk();
        }

        Http::fake($routes + ['*' => Http::response('nada', 404)]);
        $site = app(KeySites::class)->activate($owned = $this->proven('loja.com.br'), $this->user);
        $this->runJob($site);

        $asked = collect(Http::recorded())->reject(fn (array $pair) => str_contains($pair[0]->url(), 'archive.test'))->values()->map(fn (array $pair) => $pair[0]->url());
        // As páginas além da home: os links, o sitemap (pelo índice) e o caminho citado no script; nunca a API nem o que é de fora.
        foreach (['https://loja.com.br/categoria-sp', 'https://loja.com.br/categoria-rj', 'https://loja.com.br/fale-conosco', 'https://loja.com.br/shared/perfis.xml',
            'https://loja.com.br/build/lazy-Ab12.js'] as $url) {
            $this->assertContains($url, $asked, $url);
        }

        $this->assertNotContains('https://loja.com.br/api/v1/usuarios', $asked);
        $this->assertSame([], $asked->filter(fn (string $url) => str_ends_with($url, '.avif') || str_ends_with($url, '.woff2'))->values()->all(), 'o preload de imagem e de fonte não');
        $this->assertNotContains('https://loja.com.br/v2/x', $asked);
        $this->assertSame([], $asked->filter(fn (string $url) => str_contains($url, 'terceiro.com'))->values()->all());
        // Os perfis são um formato só (/perfil/:n): 3 deles, não os 11.
        $this->assertCount(KeyCrawler::PER_SHAPE, $asked->filter(fn (string $url) => str_contains($url, '/perfil/')));

        // A chave do pedaço importado, com a URL e a linha.
        $key = ExposedKey::query()->where('owned_domain_id', $owned->id)->where('kind', 'stripe_secret')->firstOrFail();
        $this->assertEquals([['host' => 'loja.com.br', 'path' => '/build/lazy-Ab12.js', 'url' => 'https://loja.com.br/build/lazy-Ab12.js', 'line' => 3, 'col' => 11]],
            $key->locations);

        $summary = $site->refresh()->last_summary;
        $this->assertSame(['ok', 42, 11], [$site->last_result, $summary['scripts'], $summary['sitemap_urls']]);
        $this->assertSame(7, $summary['pages'], 'a home, 2 categorias, o contato e 3 perfis');
    }

    public function test_the_scan_stops_when_the_site_keeps_refusing(): void
    {
        Http::fake([
            'https://loja.com.br/robots.txt' => Http::response('', 404),
            // A home responde a 1ª vez; na sonda depois dos bloqueios, também barrada: o site não quer a varredura.
            'https://loja.com.br/' => Http::sequence()->push(implode('', array_map(fn (int $i) => "<a href=\"/p{$i}\">x</a>", range(1, 30))), 200, self::HTML)
                ->whenEmpty(Http::response('bloqueado', 403)),
            '*' => Http::response('bloqueado', 403),
        ]);
        $site = app(KeySites::class)->activate($this->proven('loja.com.br'), $this->user);
        $this->runJob($site);

        $this->assertLessThanOrEqual(12, count(Http::recorded()), 'a sonda tenta a home duas vezes antes de parar');
        $this->assertSame(['partial', 'O site começou a recusar a navegação (403 ou 429 seguidos) e a varredura parou ali.'], [$site->refresh()->last_result, $site->last_error]);
    }

    public function test_the_archive_the_extra_addresses_and_the_shapes_bring_the_hidden_pages(): void
    {
        Http::fake([
            // O Web Archive conhece páginas que nenhum link público aponta mais (no http e na porta 80, como ele guarda).
            'archive.test/*' => Http::response(implode("\n", [
                'http://loja.com.br:80/redefine/facial-auth', 'https://loja.com.br/app/consumer/', 'https://loja.com.br/build/antigo.js',
                'https://loja.com.br/foto.png', 'https://terceiro.com/x', 'https://www.loja.com.br/ajuda',
            ])),
            'https://loja.com.br/robots.txt' => Http::response('', 404),
            'https://www.loja.com.br/robots.txt' => Http::response('', 404),
            'https://loja.com.br/' => Http::response('<a href="/acompanhantes-sp">sp</a><a href="/acompanhantes-rj">rj</a><a href="/acompanhantes-mg">mg</a>'
                .'<a href="/acompanhantes-ba">ba</a><a href="/quanto-ganha">artigo</a><script src="/build/app.js"></script>', 200, self::HTML),
            // O script cita a página do app (o "auth" no fim do nome não faz dela uma API) e uma API de verdade.
            'https://loja.com.br/build/app.js' => Http::response('const r=["/app/facial-auth/","/api/usuarios","/auth/token"];', 200, ['Content-Type' => 'text/javascript']),
            'https://loja.com.br/app/facial-auth/' => Http::response('<script type="module" src="/app/facial-auth/assets/index.js"></script>', 200, self::HTML),
            'https://loja.com.br/app/facial-auth/assets/index.js' => Http::response('const c={accessKeyId:"AKIAZ3MSJV4WWNKWW7XQ"};', 200, ['Content-Type' => 'text/javascript']),
            'https://loja.com.br/escondida/*' => Http::response('<title>Só o dono sabe</title>', 200, self::HTML),
            'https://loja.com.br/*' => Http::response('<title>Página</title>', 200, self::HTML),
            'https://www.loja.com.br/*' => Http::response('<title>Ajuda</title>', 200, self::HTML),
            '*' => Http::response('nada', 404),
        ]);
        $owned = $this->proven('loja.com.br');

        $result = app(KeyCrawler::class)->crawl('loja.com.br', ['/escondida/painel', 'https://loja.com.br/escondida/outro', 'https://terceiro.com/fora']);

        $asked = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->url());
        // Os endereços extras primeiro (o de fora do domínio, nunca); depois o resto.
        $this->assertSame(['https://loja.com.br/robots.txt', 'https://loja.com.br/escondida/painel', 'https://loja.com.br/escondida/outro'], $asked->reject(fn ($u) => str_contains($u, 'archive.test'))->take(3)->values()->all());
        foreach (['https://loja.com.br/redefine/facial-auth', 'https://loja.com.br/app/consumer/', 'https://www.loja.com.br/ajuda', 'https://loja.com.br/build/antigo.js',
            'https://loja.com.br/app/facial-auth/', 'https://loja.com.br/app/facial-auth/assets/index.js', 'https://loja.com.br/quanto-ganha'] as $url) {
            $this->assertContains($url, $asked, $url);
        }

        foreach (['https://loja.com.br/foto.png', 'https://loja.com.br/api/usuarios', 'https://loja.com.br/auth/token', 'https://terceiro.com/x', 'https://terceiro.com/fora'] as $url) {
            $this->assertNotContains($url, $asked, $url);
        }

        // A consulta ao archive: um retrato variado (o collapse em 18 caracteres do caminho, depois do br,com,loja)/) e sem ids.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'archive.test')
            && str_contains(urldecode($request->url()), 'url=loja.com.br/*&fl=original&collapse=urlkey:31&filter=!urlkey:.*[0-9]{4}.*&limit=5000'));

        // /acompanhantes-sp, -rj, -mg e -ba são um formato só: 3 deles.
        $this->assertCount(KeyCrawler::PER_SHAPE, $asked->filter(fn (string $url) => str_contains($url, '/acompanhantes-')));
        // Do archive: 3 páginas (o .js vai para a fila de scripts, a imagem e o de fora ficam de fora).
        $this->assertSame([3, 'aws_access_key', 'accessKeyId', 'https://loja.com.br/app/facial-auth/assets/index.js'],
            [$result['archive_urls'], $result['keys']['loja.com.br'][0]['kind'], $result['keys']['loja.com.br'][0]['name'], $result['keys']['loja.com.br'][0]['spots'][0]['url']]);
    }

    public function test_the_archive_out_of_the_air_does_not_stop_the_scan(): void
    {
        Http::fake(['archive.test/*' => Http::response('erro', 503), 'https://loja.com.br/robots.txt' => Http::response('', 404),
            'https://loja.com.br/' => Http::response('<title>Home</title>', 200, self::HTML), '*' => Http::response('nada', 404)]);

        $result = app(KeyCrawler::class)->crawl('loja.com.br');

        $this->assertSame([1, 0, true], [$result['pages'], $result['archive_urls'], $result['reached']]);
    }

    public function test_the_job_hands_the_extra_addresses_to_the_scan(): void
    {
        Http::fake(['archive.test/*' => Http::response(''), 'https://loja.com.br/robots.txt' => Http::response('', 404),
            'https://loja.com.br/' => Http::response('<title>Home</title>', 200, self::HTML),
            'https://loja.com.br/app/facial-auth/' => Http::response('<script>var c={accessKeyId:"AKIAZ3MSJV4WWNKWW7XQ"};</script>', 200, self::HTML),
            '*' => Http::response('nada', 404)]);
        $owned = $this->proven('loja.com.br');
        app(KeySites::class)->setPaths($owned, $this->user, ['/app/facial-auth/']);

        $this->runJob(app(KeySites::class)->activate($owned, $this->user));

        $this->assertSame('https://loja.com.br/app/facial-auth/', ExposedKey::query()->where('owned_domain_id', $owned->id)->sole()->locations[0]['url']);
    }

    public function test_the_authorized_recon_follows_the_password_recovery_to_the_hidden_app(): void
    {
        // Como no fatalmodel.com: o /login (no Disallow) guarda as URLs num JSON escapado; a recuperação de senha leva ao
        // fluxo facial; o app dele, em /app/facial-auth/, não tem link nenhum, mas o Web Archive mostra apps em /app/….
        Http::fake([
            'archive.test/*' => Http::response("https://loja.com.br/app/consumer/\nhttps://loja.com.br/app/consumer/history/"),
            'https://loja.com.br/robots.txt' => Http::response("User-agent: *\nDisallow: /login\nDisallow: /profile/\n"),
            'https://loja.com.br/' => Http::response('<a href="https://loja.com.br/login">Entrar</a><iframe src="/widget/chat"></iframe>', 200, self::HTML),
            'https://loja.com.br/login' => Http::response('<script>window.cfg={&quot;forgotPassword&quot;:&quot;https:\/\/loja.com.br\/redefine\/sms&quot;}</script>', 200, self::HTML),
            'https://loja.com.br/redefine/sms' => Http::response('<div data-routes=\'{"facial":"https:\/\/loja.com.br\/redefine\/facial-auth","api":"https:\/\/loja.com.br\/api\/v1\/x"}\'></div>', 200, self::HTML),
            'https://loja.com.br/redefine/facial-auth' => Http::response('<title>Recuperar com autenticação facial</title>', 200, self::HTML),
            'https://loja.com.br/app/facial-auth/' => Http::response('<script type="module" src="/app/facial-auth/assets/index.js"></script>', 200, self::HTML),
            'https://loja.com.br/app/facial-auth/assets/index.js' => Http::response('const s3={accessKeyId:"AKIAZ3MSJV4WWNKWW7XQ"};', 200, ['Content-Type' => 'text/javascript']),
            'https://loja.com.br/widget/chat' => Http::response('<title>Chat</title>', 200, self::HTML),
            '*' => Http::response('nada', 404),
        ]);

        $result = app(KeyCrawler::class)->crawl('loja.com.br');

        $asked = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->url());
        foreach (['https://loja.com.br/login', 'https://loja.com.br/profile/', 'https://loja.com.br/redefine/sms', 'https://loja.com.br/redefine/facial-auth',
            'https://loja.com.br/widget/chat', 'https://loja.com.br/app/facial-auth/', 'https://loja.com.br/app/facial-auth/assets/index.js'] as $url) {
            $this->assertContains($url, $asked, $url);
        }

        $this->assertNotContains('https://loja.com.br/api/v1/x', $asked);
        $key = $result['keys']['loja.com.br'][0];
        $this->assertSame(['aws_access_key', 'accessKeyId', 'https://loja.com.br/app/facial-auth/assets/index.js'], [$key['kind'], $key['name'], $key['spots'][0]['url']]);
    }

    public function test_a_section_behind_another_waf_is_left_out_and_the_rest_goes_on(): void
    {
        Http::fake([
            'archive.test/*' => Http::response(''),
            'https://loja.com.br/robots.txt' => Http::response('', 404),
            'https://loja.com.br/' => Http::response(implode('', array_map(fn (int $i) => "<a href=\"/blog/post-{$i}\">b</a><a href=\"/secao-{$i}/x\">s</a>", range(1, 12))), 200, self::HTML),
            'https://loja.com.br/blog/*' => Http::response('bloqueado', 403),
            '*' => Http::response('<title>Página</title>', 200, self::HTML),
        ]);

        $result = app(KeyCrawler::class)->crawl('loja.com.br');

        $asked = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->url());
        // Depois de 6 bloqueios seguidos no blog, a home responde: o blog fica de fora e as outras seções seguem.
        $this->assertLessThanOrEqual(KeyCrawler::PER_SHAPE + 6, $asked->filter(fn ($u) => str_contains($u, '/blog/'))->count());
        $this->assertGreaterThanOrEqual(3, $asked->filter(fn ($u) => str_contains($u, '/secao-'))->count());
        $this->assertFalse($result['blocked']);
    }

    public function test_sensitive_pages_jump_the_queue(): void
    {
        Http::fake([
            'archive.test/*' => Http::response(''),
            'https://loja.com.br/robots.txt' => Http::response('', 404),
            // A área de conta no fim de 40 links comuns (cada um num formato, para nenhum teto os cortar).
            'https://loja.com.br/' => Http::response(implode('', array_map(fn (string $w) => "<a href=\"/{$w}\">x</a>", ['sobre', 'blog', 'faq', 'termos', 'contato', 'parceiros',
                'imprensa', 'carreiras', 'loja', 'ofertas', 'novidades', 'marcas', 'lojas', 'cupons', 'eventos', 'cursos', 'videos', 'podcast', 'guias', 'mapa',
                'ajuda', 'trocas', 'frete', 'garantia', 'presentes', 'colecoes', 'outlet', 'lancamentos', 'categorias', 'listas', 'avaliacoes', 'perguntas',
                'servicos', 'planos', 'clube', 'indique', 'app-download', 'social', 'comunidade', 'parcerias'])).'<a href="/minha-conta/seguranca">conta</a>', 200, self::HTML),
            '*' => Http::response('<title>Página</title>', 200, self::HTML),
        ]);

        app(KeyCrawler::class)->crawl('loja.com.br');

        $pages = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->url())
            ->reject(fn (string $u) => str_contains($u, 'archive.test') || str_ends_with($u, '.txt') || str_ends_with($u, '.xml'))->values();
        // A home, e logo depois a área de conta (a fila quente sai a cada duas páginas).
        $this->assertLessThanOrEqual(3, $pages->search('https://loja.com.br/minha-conta/seguranca'));
    }

    public function test_the_scan_goes_in_slices_and_each_one_continues_where_the_last_stopped(): void
    {
        Queue::fake();
        // Etapas de 0 s: cada job lê uma coisa só e passa o estado adiante (em produção, 7 minutos).
        config(['secmon.keys.slice_seconds' => 0]);
        Http::fake([
            'archive.test/*' => Http::response(''),
            'https://loja.com.br/robots.txt' => Http::response('', 404),
            // Com um link em latin-1 (bytes que não são UTF-8): o estado guardado não pode quebrar por isso.
            'https://loja.com.br/' => Http::response("<a href=\"/sobre\">s</a><a href=\"/minha-conta/login\">l</a><a href=\"/promo\xe7\xe3o\">p</a><script src=\"/app.js\"></script>", 200, self::HTML),
            'https://loja.com.br/app.js' => Http::response('var k = "AKIAZ3MSJV4WWNKWW7XQ";', 200, ['Content-Type' => 'text/javascript']),
            'https://loja.com.br/minha-conta/login' => Http::response('<script>var s = "sk_test_51HqLyjWDarjtT1zdp7dcXYZabcQ2wEr";</script>', 200, self::HTML),
            '*' => Http::response('<title>Página</title>', 200, self::HTML),
        ]);
        $site = app(KeySites::class)->activate($owned = $this->proven('loja.com.br'), $this->user);
        $this->runJob($site);

        // Entre as etapas: rodando, com o estado guardado e as chaves achadas até ali já no registro.
        $this->assertSame('running', $site->refresh()->scan_status);
        $this->assertIsArray($site->scan_state);

        for ($i = 0; $i < 40 && $site->refresh()->scan_status === 'running'; $i++) {
            $this->assertNotNull($site->scan_state, 'a continuação sempre tem o estado');
            app()->call([new ScanSiteKeys($site->id, true), 'handle']);
        }

        $site->refresh();
        $this->assertSame(['idle', null, 'ok'], [$site->scan_status, $site->scan_state, $site->last_result]);
        $this->assertGreaterThan(3, $i, 'várias etapas');
        $this->assertSame(['aws_access_key', 'stripe_secret'], ExposedKey::query()->where('owned_domain_id', $owned->id)->orderBy('kind')->pluck('kind')->all());
        $this->assertSame([4, 1], [$site->last_summary['pages'], $site->last_summary['scripts']], 'a home, o sobre, o login e a promoção; o app.js');
        Queue::assertPushed(ScanSiteKeys::class, fn (ScanSiteKeys $job) => $job->continues && $job->siteId === $site->id);

        // A continuação de uma varredura que não está mais rodando (parada pelo dono, solta pela semanal) não faz nada.
        Http::fake(['*' => Http::response('nada', 404)]);
        app()->call([new ScanSiteKeys($site->id, true), 'handle']);
        Http::assertNothingSent();
    }

    public function test_scripts_are_queued_once_and_a_sensitive_page_brings_its_bundle_to_the_front(): void
    {
        // Como no fatalmodel.com: cada página repete os mesmos cem pedaços do Nuxt; o app de recuperação aparece depois.
        $chunks = implode('', array_map(fn (int $i) => '<link rel="modulepreload" href="/build/c'.$i.'.js">', range(1, 100)));
        Http::fake([
            'archive.test/*' => Http::response(''),
            'https://loja.com.br/robots.txt' => Http::response('', 404),
            'https://loja.com.br/' => Http::response($chunks.implode('', array_map(fn (string $w) => "<a href=\"/{$w}\">x</a>", ['sobre', 'ajuda', 'termos', 'blog'])).'<a href="/app/facial-auth/">f</a>', 200, self::HTML),
            'https://loja.com.br/app/facial-auth/' => Http::response('<script type="module" src="/app/facial-auth/assets/index.js"></script>', 200, self::HTML),
            'https://loja.com.br/app/facial-auth/assets/index.js' => Http::response('const s3={accessKeyId:"AKIAZ3MSJV4WWNKWW7XQ"};', 200, ['Content-Type' => 'text/javascript']),
            'https://loja.com.br/build/*' => Http::response('export const a=1;', 200, ['Content-Type' => 'text/javascript']),
            '*' => Http::response($chunks.'<title>Página</title>', 200, self::HTML),
        ]);

        $result = app(KeyCrawler::class)->crawl('loja.com.br');

        $scripts = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->url())->filter(fn (string $u) => str_ends_with($u, '.js'))->values();
        // Cada pedaço uma vez só, e o bundle do app entre os primeiros scripts, não depois dos cem pedaços.
        $this->assertSame($scripts->count(), $scripts->unique()->count());
        $this->assertLessThan(5, $scripts->search('https://loja.com.br/app/facial-auth/assets/index.js'));
        $this->assertSame('aws_access_key', $result['keys']['loja.com.br'][0]['kind']);
    }

    public function test_a_host_that_refuses_its_scripts_is_left_out_and_a_momentary_500_does_not_stop_the_scan(): void
    {
        Http::fake([
            'archive.test/*' => Http::response(''),
            '*/robots.txt' => Http::response('', 404),
            // Os scripts de um nome que nega todos, misturados com páginas boas.
            'https://loja.com.br/' => Http::sequence()
                ->push(implode('', array_map(fn (int $i) => "<script src=\"https://cdn.loja.com.br/js/s{$i}.js\"></script><a href=\"/area".chr(96 + $i).'/x">s</a>', range(1, 20))), 200, self::HTML)
                ->push('erro', 500)
                ->whenEmpty(Http::response('<title>Home</title>', 200, self::HTML)),
            'https://cdn.loja.com.br/*' => Http::response('negado', 403),
            '*' => Http::response('<title>Página</title>', 200, self::HTML),
        ]);

        $result = app(KeyCrawler::class)->crawl('loja.com.br');

        $asked = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->url());
        // O cdn fica de fora depois de 6 recusas; as seções do site seguem até o fim, sem parar por bloqueio.
        $this->assertLessThanOrEqual(6, $asked->filter(fn (string $u) => str_contains($u, 'cdn.loja.com.br/js/'))->count());
        $this->assertGreaterThanOrEqual(10, $asked->filter(fn (string $u) => preg_match('~/area[a-t]/x$~', $u))->count());
        $this->assertFalse($result['blocked']);
    }

    public function test_full_queues_do_not_leave_out_the_links_and_the_scripts_of_a_new_page(): void
    {
        // Como no fatalmodel.com: cada página repete centenas de links (o menu, as cidades), e o app de recuperação só é
        // linkado numa página lida depois de muitas. Com os repetidos na fila, ela enchia e essa página não punha mais nada.
        $menu = ['sobre', 'blog', 'faq', 'termos', 'contato', 'parceiros', 'imprensa', 'carreiras', 'loja', 'ofertas', 'novidades', 'marcas', 'lojas', 'cupons',
            'eventos', 'cursos', 'videos', 'podcast', 'guias', 'mapa', 'ajuda', 'trocas', 'frete', 'garantia', 'presentes', 'colecoes', 'outlet', 'planos'];
        $links = implode('', array_map(fn (string $w) => "<a href=\"/{$w}\">x</a>", $menu))
            .implode('', array_map(fn (int $i) => '<a href="/cidade-'.chr(97 + intdiv($i, 26)).chr(97 + $i % 26).'">c</a>', range(0, 399)));
        Http::fake([
            'archive.test/*' => Http::response(''),
            'https://loja.com.br/robots.txt' => Http::response('', 404),
            'https://loja.com.br/planos' => Http::response($links.'<a href="/app/facial-auth/">f</a>', 200, self::HTML),
            'https://loja.com.br/app/facial-auth/' => Http::response('<script type="module" src="/app/facial-auth/assets/index.js"></script>', 200, self::HTML),
            'https://loja.com.br/app/facial-auth/assets/index.js' => Http::response('const s3={accessKeyId:"AKIAZ3MSJV4WWNKWW7XQ"};', 200, ['Content-Type' => 'text/javascript']),
            '*' => Http::response($links, 200, self::HTML),
        ]);

        $result = app(KeyCrawler::class)->crawl('loja.com.br');

        $asked = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->url());
        $this->assertContains('https://loja.com.br/app/facial-auth/', $asked);
        $this->assertContains('https://loja.com.br/app/facial-auth/assets/index.js', $asked);
        $this->assertSame('aws_access_key', $result['keys']['loja.com.br'][0]['kind']);
        // Nada repetido, as cidades só até o teto do formato, e a varredura acaba (a fila não ficou com sobras).
        $this->assertSame($asked->count(), $asked->unique()->count());
        $this->assertSame(KeyCrawler::PER_SHAPE, $asked->filter(fn (string $u) => str_contains($u, '/cidade-'))->count());
        $this->assertFalse($result['truncated']);
    }

    public function test_an_article_about_login_does_not_go_ahead_of_the_login(): void
    {
        $this->dns->set('ajuda.loja.com.br', 'ips', ['34.1.1.3']);
        Http::fake([
            'archive.test/*' => Http::response(''),
            '*/robots.txt' => Http::response('', 404),
            // O login primeiro; depois, o que só parece área sensível: "sso" no meio de "acessorios" e os artigos da central
            // de ajuda (o id na frente, o título comprido), no site e num subdomínio.
            'https://loja.com.br/' => Http::response('<a href="/login">Entrar</a><a href="/servico/utilizar-acessorios-eroticos">s</a>'
                .'<a href="https://ajuda.loja.com.br/pt-BR/articles/15363265-esqueci-minha-senha-ou-nao-consigo-entrar">a</a>'
                .'<a href="/ajuda/como-recuperar-a-senha-da-minha-conta">b</a><a href="https://www.instagram.com/loja-login">i</a>', 200, self::HTML),
            '*' => Http::response('<title>Página</title>', 200, self::HTML),
        ]);

        app(KeyCrawler::class)->crawl('loja.com.br');

        $pages = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->url())
            ->reject(fn (string $u) => str_contains($u, 'archive.test') || str_ends_with($u, '.txt') || str_ends_with($u, '.xml'))->values();
        // A home, o login, e só então o resto (antes, os três de aparência sensível passavam na frente dele).
        $this->assertSame(['https://loja.com.br/', 'https://loja.com.br/login'], $pages->take(2)->all());
        $this->assertCount(5, $pages, 'o Instagram está fora do domínio');
    }

    private function proven(string $name): OwnedDomain
    {
        return OwnedDomain::query()->forceCreate(['user_id' => $this->user->id, 'domain' => $name, 'source' => 'radar', 'verified_at' => now()]);
    }

    private function runJob(KeySite $site): void
    {
        app()->call([new ScanSiteKeys($site->id), 'handle']);
    }

    private function refused(callable $call): string
    {
        try {
            $call();
        } catch (ActionRefused $e) {
            return $e->getMessage();
        }

        $this->fail('Não foi recusado.');
    }
}
