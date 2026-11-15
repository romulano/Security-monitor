<?php

namespace Tests\Feature\Alerts;

use App\Jobs\DiscoverDomainStack;
use App\Models\DomainFinding;
use App\Models\MonitoredDomain;
use App\Models\TechCatalog;
use App\Services\Alerts\Discovery\DiscoveryService;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\Discovery\IpGuard;
use App\Services\Alerts\Discovery\StackDetector;
use App\Services\Alerts\InterviewService;
use App\Services\Alerts\ProfileIndex;
use App\Services\Domains\DomainOwnership;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Pentest\PentestRunFixtures;
use Tests\TestCase;

/** Etapa 6: prova de posse, descoberta passiva e anti-SSRF. */
class DiscoveryTest extends TestCase
{
    use AlertsTestHelpers, PentestRunFixtures, RefreshDatabase;

    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
        // URL sem stub nunca sai para a internet.
        Http::preventStrayRequests();
    }

    private function domain(array $attributes = [], array $techs = ['nginx']): MonitoredDomain
    {
        $profile = $this->profile(null, [], $techs);

        return $profile->domains()->create($attributes + [
            'domain' => 'acmepay.com.br',
            'verify_token' => 'abc123',
            'consent_at' => now(),
            'consent_version' => 'v1',
            'verified_at' => now(),
        ]);
    }

    /** Um site típico: redireciona para www e entrega cabeçalhos e HTML reveladores. */
    private function fakeSite(): void
    {
        $this->dns->set('acmepay.com.br', 'ips', ['104.21.1.1'])
            ->set('www.acmepay.com.br', 'ips', ['172.67.1.1'])
            ->set('acmepay.com.br', 'mx', ['aspmx.l.google.com', 'alt1.aspmx.l.google.com'])
            ->set('acmepay.com.br', 'txt', ['secmon-verify=abc123', 'v=spf1 include:_spf.google.com include:sendgrid.net ~all'])
            ->set('acmepay.com.br', 'ns', ['ana.ns.cloudflare.com']);

        Http::fake([
            'https://acmepay.com.br/' => Http::response('', 301, ['Location' => 'https://www.acmepay.com.br/']),
            'https://www.acmepay.com.br/' => Http::response(
                '<html><head><script src="https://js.hs-scripts.com/123.js"></script></head><body>Acme</body></html>',
                200,
                [
                    'Server' => 'nginx/1.24.0 (Ubuntu)',
                    'X-Powered-By' => 'PHP/8.2.12',
                    'CF-RAY' => '8a1b2c3d4e-GRU',
                    'Set-Cookie' => ['XSRF-TOKEN=x; path=/', 'laravel_session=y; path=/; httponly'],
                ],
            ),
        ]);
    }

    public function test_discovery_waits_while_a_security_test_runs_on_the_site(): void
    {
        // T10: com um teste de segurança no domínio, a descoberta e a releitura das páginas barradas não leem o site.
        $domain = $this->domain(['hints' => [['tipo' => 'bloqueio', 'url' => 'https://acmepay.com.br/blog/', 'por' => 'cloudflare']]]);
        $run = $this->activeRun($domain->profile->user, 'acmepay.com.br');

        $this->assertFalse(app(DiscoveryService::class)->run($domain));
        $this->assertSame(0, app(DiscoveryService::class)->retryWalls($domain->refresh()));
        Http::assertNothingSent();
        $this->assertNull($domain->refresh()->last_scan_at);

        // Terminado o teste, ela roda.
        $this->moveRun($run, 'completed');
        $this->fakeSite();
        $this->assertTrue(app(DiscoveryService::class)->run($domain));
    }

    public function test_ip_guard_blocks_everything_internal(): void
    {
        foreach (['10.0.0.12', '172.20.0.5', '192.168.1.1', '127.0.0.1', '169.254.169.254', '100.64.1.1', '0.0.0.0',
            '::1', 'fd00::1', 'fe80::1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '64:ff9b::a00:1', 'not-an-ip'] as $ip) {
            $this->assertFalse(IpGuard::isPublic($ip), $ip);
        }

        foreach (['8.8.8.8', '104.21.1.1', '2606:4700:4700::1111'] as $ip) {
            $this->assertTrue(IpGuard::isPublic($ip), $ip);
        }

        $this->assertSame('link-local / metadata da nuvem', IpGuard::blockReason('169.254.169.254'));
    }

    public function test_txt_verification_unlocks_discovery(): void
    {
        Queue::fake();
        $domain = $this->domain(['verified_at' => null]);
        $service = app(DomainOwnership::class);

        $this->assertFalse($service->verifyRow($domain, 'news'));
        $this->assertNull($domain->refresh()->verified_at);
        $this->assertNotNull($domain->verify_checked_at);
        Queue::assertNothingPushed();

        $this->dns->set('acmepay.com.br', 'txt', ['outro=1', ' secmon-verify=abc123 ']);
        $this->assertTrue($service->verifyRow($domain, 'news'));
        $this->assertNotNull($domain->refresh()->verified_at);
        Queue::assertPushed(DiscoverDomainStack::class, fn ($job) => $job->domainId === $domain->id && $job->queue === 'discovery');
    }

    public function test_client_verify_button_and_scheduled_check(): void
    {
        Queue::fake();
        $domain = $this->domain(['verified_at' => null]);
        $user = $domain->profile->user;

        $this->actingAs($user)->post(route('alerts.domain.verify'))->assertSessionHas('error');

        $this->dns->set('acmepay.com.br', 'txt', ['secmon-verify=abc123']);
        $this->artisan('secmon:alerts-verify-domains')->expectsOutputToContain('1 verificado');
        $this->assertTrue($domain->refresh()->isVerified());

        // Fora da janela de 72 h o agendador desiste.
        $old = $this->domain(['domain' => 'velho.com.br', 'verified_at' => null, 'consent_at' => now()->subHours(80)]);
        $this->dns->set('velho.com.br', 'txt', ['secmon-verify=abc123']);
        $this->artisan('secmon:alerts-verify-domains')->expectsOutputToContain('0 domínio');
        $this->assertNull($old->refresh()->verified_at);
    }

    public function test_discovery_finds_the_stack_with_evidence(): void
    {
        $this->fakeSite();
        $domain = $this->domain();

        app(DiscoveryService::class)->run($domain);
        $domain->refresh();

        $this->assertSame('done', $domain->discovery_status);
        $this->assertEqualsWithDelta(30, now()->diffInDays($domain->next_run_at), 0.1);

        $findings = $domain->findings()->get()->keyBy('label');
        $this->assertEqualsCanonicalizing(
            ['Nginx 1.24', 'Ubuntu', 'Linux', 'PHP 8.2', 'Laravel', 'Cloudflare', 'Google Workspace', 'SendGrid', 'HubSpot'],
            $findings->keys()->all(),
        );
        $this->assertSame('deduzido de Ubuntu (Server: nginx/1.24.0 (Ubuntu))', $findings['Linux']->evidence);
        $this->assertSame(80, $findings['Linux']->confidence);
        $this->assertSame('Server: nginx/1.24.0 (Ubuntu)', $findings['Nginx 1.24']->evidence);
        $this->assertSame('cookie laravel_session', $findings['Laravel']->evidence);
        $this->assertSame('MX aspmx.l.google.com', $findings['Google Workspace']->evidence);
        $this->assertSame('SPF include:sendgrid.net', $findings['SendGrid']->evidence);
        $this->assertSame(90, $findings['Nginx 1.24']->confidence);
        $this->assertSame('média', $findings['HubSpot']->confidenceLabel());

        $this->assertStringContainsString('expõe a versão exata', $domain->hints[0]);
        $this->assertStringContainsString('Server: nginx/1.24.0', $domain->hints[0]);
        $log = collect($domain->last_log)->pluck('msg')->implode("\n");
        $this->assertStringContainsString('GET https://acmepay.com.br/ → 301 https://www.acmepay.com.br/', $log);
        $this->assertStringContainsString('www.acmepay.com.br → 172.67.1.1 (público)', $log);

        // Um GET por host, sem formulário, sem crawl, com o User-Agent do Security Monitor.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->header('User-Agent')[0] ?? '', 'SecurityMonitorBot'));
    }

    public function test_lovable_app_is_recognized_and_reveals_react(): void
    {
        $detector = app(StackDetector::class);
        $found = collect($detector->withParents($detector->detect([
            'headers' => [], 'cookies' => [], 'mx' => [], 'spf' => [], 'ns' => [], 'cname' => ['acme.lovable.app'],
            'html' => '<head><meta name="description" content="Lovable Generated Project" /><meta name="author" content="Lovable" /></head>',
            'scripts' => ['cdn.gpteng.co'],
        ])))->keyBy('label');

        $this->assertSame('script de cdn.gpteng.co', $found['Lovable']['evidence']);
        $this->assertSame('deduzido de Lovable (script de cdn.gpteng.co)', $found['React']['evidence']);
        $this->assertTrue($found->has('JavaScript / TypeScript'));
    }

    /** Regressão: MX da Zoho e SPF de Zendesk/Mailgun/RD Station passavam sem virar achado. */
    public function test_email_providers_are_recognized_from_mx_and_spf(): void
    {
        $found = collect(app(StackDetector::class)->detect([
            'headers' => [], 'cookies' => [], 'html' => '',
            'mx' => ['mx.zoho.com', 'mx2.zoho.com'],
            'spf' => ['v=spf1 include:zohomail.com include:amazonses.com include:mail.zendesk.com include:mailgun.org include:_spf.rdstation.com.br include:sendgrid.net -all'],
            'ns' => [],
        ]))->keyBy('label');

        $this->assertEqualsCanonicalizing(
            ['Zoho Mail', 'Amazon SES', 'Zendesk', 'Mailgun', 'RD Station', 'SendGrid'],
            $found->keys()->all(),
        );
        $this->assertSame('MX mx.zoho.com', $found['Zoho Mail']['evidence']);
        $this->assertSame(90, $found['Zoho Mail']['confidence']);
        $this->assertSame('SPF include:_spf.rdstation.com.br', $found['RD Station']['evidence']);
    }

    /** Uma loja cheia de sinais que nenhuma regra do catálogo conhece. */
    private function fakeUnknownSite(): void
    {
        $this->dns->set('lojinha.com.br', 'ips', ['104.21.2.2'])
            ->set('lojinha.com.br', 'mx', ['mx.locaweb.com.br', 'mx2.locaweb.com.br'])
            ->set('lojinha.com.br', 'txt', [
                'secmon-verify=abc123',
                'v=spf1 include:spf.mailerlite.com include:_spf.lojinha.com.br ~all',
                'acmecorp-site-verification=xyz789',
                'google-site-verification=qwerty',
            ])
            ->set('lojinha.com.br', 'ns', ['ns1.hostgator.com.br', 'ns2.hostgator.com.br'])
            ->set('www.lojinha.com.br', 'cname', ['lojinha.netlify.app']);

        Http::fake([
            'https://lojinha.com.br/' => Http::response(
                '<html><head><meta name="generator" content="SuperCMS 2.1.4"><meta name="generator" content="Elementor 3.20.1">'
                .'<link rel="stylesheet" href="/wp-content/themes/astra/style.css">'
                .'<script src="/wp-content/plugins/contact-form-7/index.js"></script>'
                .'<script src="https://widget.chatfacil.io/w.js"></script>'
                .'<script src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script>'
                .'<script src="https://static.lojinha.com.br/app.js"></script>'
                .'<script src="https://d111111abcdef8.cloudfront.net/lib.js"></script>'
                .'</head><body>Loja</body></html>',
                200,
                ['Server' => 'Kestrel', 'X-Powered-By' => 'Phusion Passenger 6.0.17'],
            ),
        ]);
    }

    public function test_signals_outside_the_catalog_are_registered_for_monitoring(): void
    {
        $this->fakeUnknownSite();
        $domain = $this->domain(['domain' => 'lojinha.com.br'], []);
        $profile = $domain->profile;

        app(DiscoveryService::class)->run($domain);

        $auto = TechCatalog::query()->where('origin', 'discovery')->get()->keyBy('name');
        $this->assertEqualsCanonicalizing([
            'locaweb.com.br', 'mailerlite.com', 'hostgator.com.br', 'netlify.app', 'Acmecorp', 'Kestrel', 'Phusion Passenger',
            'SuperCMS', 'chatfacil.io', 'WordPress plugin: Contact Form 7', 'WordPress tema: Astra',
        ], $auto->keys()->all(), 'Elementor e GTM já são do catálogo; Google, script próprio e host de CDN não dizem que produto é');

        foreach ($auto as $tech) {
            $this->assertTrue($tech->strict && $tech->active, $tech->name);
            $this->assertSame('lojinha.com.br', $tech->discovered_on);
            $this->assertSame('discovery', $profile->techs()->where('tech_catalog.id', $tech->id)->first()?->pivot->source, $tech->name);
        }

        // O item nasce com a regra que o achou e com termos que aparecem em notícia.
        $this->assertSame(['locaweb', 'locaweb.com.br'], $auto['locaweb.com.br']->terms);
        $this->assertSame('identity', $auto['locaweb.com.br']->category);
        $this->assertSame([['type' => 'cname', 'pattern' => 'netlify.app']], $auto['netlify.app']->detect);
        $this->assertSame(['phusion passenger'], $auto['Phusion Passenger']->terms);
        $this->assertSame(['contact form 7', 'contact-form-7'], $auto['WordPress plugin: Contact Form 7']->terms);
        $this->assertSame('wordpress', $auto['WordPress plugin: Contact Form 7']->parent->slug);

        $findings = $domain->findings()->get()->keyBy('label');
        $this->assertSame('X-Powered-By: Phusion Passenger 6.0.17', $findings['Phusion Passenger 6.0']->evidence);
        $this->assertSame('script de widget.chatfacil.io', $findings['chatfacil.io']->evidence);
        $this->assertSame('CNAME lojinha.netlify.app', $findings['netlify.app']->evidence);
        $this->assertTrue($findings->has('Elementor'));
        $this->assertTrue($findings->has('Google Tag Manager'));
        $this->assertStringContainsString('"chatfacil"', $profile->refresh()->match_query);

        $log = collect($domain->refresh()->last_log)->pluck('msg')->implode("\n");
        $this->assertStringContainsString('11 item(ns) fora do catálogo cadastrado(s) para monitoria', $log);
        $this->assertStringContainsString('DNS CNAME www → lojinha.netlify.app', $log);
        $this->assertStringContainsString('página: 2 meta generator, 3 script(s) de terceiros, 2 plugin(s)/tema(s) WordPress', $log);

        // Rodar de novo (no mês seguinte) não duplica nada.
        $this->travel(30)->days();
        app(DiscoveryService::class)->run($domain->refresh());
        $this->assertSame(11, TechCatalog::query()->where('origin', 'discovery')->count());
        $this->assertStringNotContainsString('fora do catálogo', collect($domain->refresh()->last_log)->pluck('msg')->implode("\n"));

        // Outro cliente com o mesmo provedor já é reconhecido pela regra aprendida.
        $this->dns->set('outra.com.br', 'ips', ['104.21.3.3'])->set('outra.com.br', 'mx', ['mx.core.locaweb.com.br']);
        Http::fake(['https://outra.com.br/' => Http::response('<html></html>', 200)]);
        $other = $this->domain(['domain' => 'outra.com.br'], []);
        app(DiscoveryService::class)->run($other);
        $this->assertSame('MX mx.core.locaweb.com.br', $other->findings()->where('label', 'locaweb.com.br')->value('evidence'));
        $this->assertSame(11, TechCatalog::query()->where('origin', 'discovery')->count());

        // O admin acha tudo num filtro, para revisar nome e termos.
        $this->actingAs($this->adminUser())->get(route('admin.alerts.catalog', ['origem' => 'discovery']))->assertOk()
            ->assertSee('Descoberta automática (11)')
            ->assertSee('descoberta automática · lojinha.com.br')
            ->assertSee('Phusion Passenger')
            ->assertDontSee('Nginx');
    }

    /** Home em cache (front Nuxt) e o Laravel só aparecendo fora dela: caso real que motivou a página de erro. */
    public function test_error_page_shows_the_app_server_behind_the_cache(): void
    {
        $this->dns->set('acmepay.com.br', 'ips', ['104.21.1.1']);
        Http::fake([
            'https://acmepay.com.br/secmon-check-*' => Http::response('<html>Página não encontrada</html>', 404, [
                'Server' => 'cloudflare',
                'Cache-Control' => 'no-cache, private',
                'Set-Cookie' => ['XSRF-TOKEN=x; path=/', 'acme_laravel_session=y; path=/; httponly'],
            ]),
            'https://acmepay.com.br/' => Http::response('<html><script>window.__NUXT__={}</script></html>', 200, ['Server' => 'cloudflare', 'X-Powered-By' => 'Nuxt']),
        ]);
        $domain = $this->domain([], []);

        // Aceite do termo v1: só a página inicial, então o Laravel não aparece.
        app(DiscoveryService::class)->run($domain);
        Http::assertSentCount(1);
        $this->assertFalse($domain->findings()->where('label', 'Laravel')->exists());
        $this->assertStringContainsString('termo v1: só a página inicial', collect($domain->refresh()->last_log)->pluck('msg')->implode("\n"));

        // Termo v2 (na varredura do mês seguinte): a página de erro vem do servidor de aplicação.
        $domain->forceFill(['consent_version' => 'v2'])->save();
        $this->travel(30)->days();
        app(DiscoveryService::class)->run($domain);
        Http::assertSent(fn (Request $r) => preg_match('#^https://acmepay\.com\.br/secmon-check-[0-9a-f]{10}$#', $r->url()) === 1);

        $findings = $domain->findings()->get()->keyBy('label');
        $this->assertSame('cookie acme_laravel_session', $findings['Laravel']->evidence);
        $this->assertSame('deduzido de Laravel (cookie acme_laravel_session)', $findings['PHP']->evidence);
        $this->assertSame(80, $findings['PHP']->confidence);
        $this->assertSame('deduzido de Nuxt (HTML contém __NUXT__)', $findings['JavaScript / TypeScript']->evidence);
        $this->assertSame('discovery', $domain->profile->techs()->where('slug', 'php')->first()->pivot->source);
        $log = collect($domain->refresh()->last_log)->pluck('msg')->implode("\n");
        $this->assertStringContainsString('página de erro: cookies XSRF-TOKEN, acme_laravel_session; cabeçalhos cache-control', $log);
        $this->assertSame('info', collect($domain->last_log)->first(fn ($l) => str_contains($l['msg'], '/secmon-check-'))['level'], '404 ali é o esperado');
        $this->assertStringNotContainsString('termo v', $log);
        // Sem link para um blog, nada de tentar /blog/ às cegas: 2 requisições no total.
        $this->assertStringContainsString('blog: a página inicial não linka um blog; nada a ler', $log);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/blog'));
        $this->assertStringContainsString('2 requisição(ões) ao site nesta varredura (teto 5)', $log);
    }

    /** O blog é outro sistema: um WordPress em /blog/ ao lado do app. */
    public function test_blog_linked_from_the_home_page_is_read(): void
    {
        $this->dns->set('acmepay.com.br', 'ips', ['104.21.1.1']);
        Http::fake([
            'https://acmepay.com.br/secmon-check-*' => Http::response('', 404),
            'https://acmepay.com.br/blog/' => Http::response(
                '<html><head><meta name="generator" content="WordPress 6.4.3"></head><body>'
                .'<link rel="stylesheet" href="https://acmepay.com.br/blog/wp-content/plugins/wordpress-seo/css/x.css">'
                .'<script src="https://acmepay.com.br/blog/wp-content/themes/hello-elementor/app.js"></script></body></html>',
                200,
                ['Server' => 'nginx', 'Set-Cookie' => ['wordpress_test_cookie=WP%20Cookie; path=/blog/']],
            ),
            'https://acmepay.com.br/' => Http::response('<html><a href="https://outrosite.com/blog/">parceiro</a><a href="/blog/2024/10/novidades">Blog</a></html>', 200),
        ]);
        $domain = $this->domain(['consent_version' => 'v2'], []);

        app(DiscoveryService::class)->run($domain);

        $findings = $domain->findings()->get()->keyBy('label');
        $this->assertSame('meta generator "WordPress 6.4.3"', $findings['WordPress 6.4']->evidence);
        $this->assertSame('deduzido de WordPress 6.4 (meta generator "WordPress 6.4.3")', $findings['PHP']->evidence);
        $this->assertTrue($findings->has('WordPress plugin: Wordpress Seo'));
        $this->assertTrue($findings->has('WordPress tema: Hello Elementor'));

        $log = collect($domain->refresh()->last_log)->pluck('msg')->implode("\n");
        $this->assertStringContainsString('blog: link na página inicial → https://acmepay.com.br/blog/', $log, 'link de outro site não conta');
        $this->assertStringContainsString('blog: meta generator WordPress 6.4.3; 2 plugin(s)/tema(s) WordPress; cookies wordpress_test_cookie; cabeçalhos server', $log);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'outrosite.com'));
    }

    public function test_blog_behind_an_anti_bot_challenge_becomes_a_notice_to_the_client(): void
    {
        $this->dns->set('acmepay.com.br', 'ips', ['104.21.1.1']);
        Http::fake([
            'https://acmepay.com.br/secmon-check-*' => Http::response('', 404),
            'https://acmepay.com.br/blog/' => Http::response(
                '<html><head><title>Just a moment...</title></head><body>wp-content</body></html>',
                403,
                ['Server' => 'cloudflare', 'cf-mitigated' => 'challenge'],
            ),
            'https://acmepay.com.br/' => Http::response('<html><a href="/blog/">Blog</a></html>', 200, ['Server' => 'cloudflare']),
        ]);
        $domain = $this->domain(['consent_version' => 'v2'], []);

        app(DiscoveryService::class)->run($domain);
        $domain->refresh();

        // Nada do desafio vira sinal, e o Security Monitor não tenta passar por ele.
        $this->assertFalse($domain->findings()->where('label', 'like', 'WordPress%')->exists());
        Http::assertSentCount(3);
        $log = collect($domain->last_log);
        $this->assertSame('error', $log->firstWhere('msg', 'blog: barrada pelo desafio anti-robô da Cloudflare em https://acmepay.com.br/blog/')['level'] ?? null);

        $notice = collect($domain->hints)->firstWhere('tipo', 'bloqueio');
        $this->assertStringContainsString('libere o User-Agent com "SecurityMonitorBot"', $notice['texto']);
        $this->assertStringContainsString('Security → WAF → Custom rules', $notice['texto']);

        $this->assertEquals(['url' => 'https://acmepay.com.br/blog/', 'por' => 'cloudflare'], array_intersect_key($notice, ['url' => 1, 'por' => 1]));

        // No passo 5: o bloqueio e o passo a passo já aberto, com a regra pronta para colar.
        $user = $domain->profile->user;
        $step = $this->actingAs($user)->get(route('alerts.interview', 5))->assertOk()
            ->assertSee('Não conseguimos ler tudo:')
            ->assertSee('foi barrada')
            ->assertSee('pelo desafio anti-robô da Cloudflare')
            ->assertSee('O passo a passo abaixo libera o Security Monitor.')
            ->assertSee('Liberar o Security Monitor na Cloudflare')
            ->assertSee('necessário')
            ->assertSee('(http.user_agent contains "SecurityMonitorBot" and (http.request.uri.path in {"/" "/blog/"} or http.request.uri.path contains "/secmon-check-"))')
            ->assertSee('Ler de novo as páginas barradas');
        $this->assertMatchesRegularExpression('/id="liberar-cloudflare"\s+open/', $step->getContent());

        // Na home de alertas, o aviso leva direto ao passo a passo.
        $this->get(route('alerts.news'))->assertOk()
            ->assertSee('Não conseguimos ler tudo em acmepay.com.br.')
            ->assertSee('desafio anti-robô da Cloudflare')
            ->assertSee(route('alerts.interview', 5).'#liberar-cloudflare', false);

        $this->actingAs($this->adminUser())->get(route('admin.alerts.domains', ['log' => $domain->id]))
            ->assertSee('Aviso ao cliente: não conseguimos ler https://acmepay.com.br/blog/');
    }

    public function test_cloudflare_release_is_a_recommendation_when_nothing_was_blocked(): void
    {
        $domain = $this->domain(['last_run_at' => now()->subDay(), 'discovery_status' => 'done'], []);
        $cloudflare = $domain->findings()->create([
            'tech_id' => TechCatalog::query()->where('slug', 'cloudflare')->value('id'),
            'label' => 'Cloudflare', 'evidence' => 'Cf-Ray: 1-GRU', 'confidence' => 90, 'status' => 'accepted',
        ]);
        $this->actingAs($domain->profile->user);

        $step = $this->get(route('alerts.interview', 5))->assertOk()->assertSee('Liberar o Security Monitor na Cloudflare')->assertSee('recomendado');
        $this->assertDoesNotMatchRegularExpression('/id="liberar-cloudflare"\s+open/', $step->getContent());
        $this->get(route('alerts.news'))->assertDontSee('Não conseguimos ler tudo');

        // Com os IPs de saída configurados, a regra também exige o IP.
        config(['secmon.alerts.discovery.egress_ips' => ['203.0.113.7', '2001:db8::7']]);
        $this->get(route('alerts.interview', 5))
            ->assertSee('or http.request.uri.path contains "/secmon-check-") and ip.src in {203.0.113.7 2001:db8::7})')
            ->assertSee('e só para os IPs do Security Monitor');

        // Cloudflare removida pelo cliente, ou ausente: nada de passo a passo.
        $cloudflare->update(['status' => 'ignored']);
        $this->get(route('alerts.interview', 5))->assertDontSee('Liberar o Security Monitor na Cloudflare');
        $cloudflare->delete();
        $this->get(route('alerts.interview', 5))->assertDontSee('Liberar o Security Monitor na Cloudflare');
    }

    /** Depois de liberar o robô: só as páginas barradas, 1 vez por dia, sem contar como varredura. */
    public function test_client_rereads_only_the_blocked_pages_once_a_day(): void
    {
        $this->dns->set('acmepay.com.br', 'ips', ['104.21.1.1']);
        $challenge = ['<html><head><title>Just a moment...</title></head></html>', 403, ['Server' => 'cloudflare', 'cf-mitigated' => 'challenge']];
        Http::fake([
            'https://acmepay.com.br/secmon-check-*' => Http::response('', 404),
            'https://acmepay.com.br/blog/' => Http::sequence()->push(...$challenge)->push(...$challenge)
                ->push('<html><head><meta name="generator" content="WordPress 6.4.3"></head></html>', 200, ['Server' => 'cloudflare']),
            'https://acmepay.com.br/' => Http::response('<html><a href="/blog/">Blog</a></html>', 200, ['Server' => 'cloudflare']),
        ]);
        $domain = $this->domain(['consent_version' => 'v2'], []);
        $this->actingAs($domain->profile->user);

        // Sem nada barrado, não há o que reler.
        $this->post(route('alerts.domain.rerun'))->assertNotFound();

        app(DiscoveryService::class)->run($domain);
        Http::assertSentCount(3);
        $scannedAt = $domain->refresh()->last_scan_at;
        $this->get(route('alerts.interview', 5))->assertSee('Ler de novo as páginas barradas')->assertSee('1 vez por dia');

        // 1ª releitura (fila síncrona nos testes): pede só o blog, que continua barrado.
        $this->post(route('alerts.domain.rerun'))->assertSessionHas('status');
        Http::assertSentCount(4);
        $this->assertCount(1, $domain->refresh()->walls());
        $this->assertStringContainsString('releitura: barrada pelo desafio anti-robô da Cloudflare', collect($domain->last_log)->pluck('msg')->implode("\n"));

        // No mesmo dia, não.
        $this->post(route('alerts.domain.rerun'))->assertSessionHas('error');
        Http::assertSentCount(4);
        $this->get(route('alerts.interview', 5))->assertSee('Já tentamos hoje');

        // No dia seguinte, com o robô liberado, o blog entra.
        $this->travel(25)->hours();
        $this->post(route('alerts.domain.rerun'))->assertSessionHas('status');
        Http::assertSentCount(5);
        $domain->refresh();
        $this->assertSame([], $domain->walls());
        $this->assertTrue($domain->findings()->where('label', 'WordPress 6.4')->exists());
        $this->assertTrue($domain->last_scan_at->equalTo($scannedAt), 'releitura não é varredura: o mês não reinicia');
        $this->assertStringContainsString('1 de 1 página(s) lida(s) agora', collect($domain->last_log)->last()['msg']);
        $this->get(route('alerts.news'))->assertDontSee('Não conseguimos ler tudo');
        $this->post(route('alerts.domain.rerun'))->assertNotFound();
    }

    public function test_a_site_is_read_at_most_once_a_month_whatever_the_trigger(): void
    {
        $this->fakeSite();
        $domain = $this->domain();

        $this->assertTrue(app(DiscoveryService::class)->run($domain));
        Http::assertSentCount(2);

        // Serviço, fila, comando, agendador e admin: dentro do mês, nenhuma requisição a mais.
        $this->assertFalse(app(DiscoveryService::class)->run($domain->refresh()));
        DiscoverDomainStack::dispatchSync($domain->id);
        $this->artisan('secmon:alerts-discover', ['domain' => 'acmepay.com.br'])->expectsOutputToContain('(1 por mês)')->assertSuccessful();
        $this->artisan('secmon:alerts-discover', ['--due' => true])->expectsOutputToContain('0 domínio');
        $this->actingAs($this->adminUser())->post(route('admin.alerts.domains.run', $domain))->assertSessionHas('error');
        $this->get(route('admin.alerts.domains'))->assertSee('liberado em')->assertDontSee('Rodar agora');
        Http::assertSentCount(2);

        // O admin pode espaçar mais, nunca menos que 30 dias.
        Settings::set('alerts.discovery_every_days', 1);
        $this->assertSame(30, MonitoredDomain::scanIntervalDays());
        $this->assertFalse(app(DiscoveryService::class)->run($domain->refresh()));
        Settings::set('alerts.discovery_every_days', 45);
        $this->assertSame(45, MonitoredDomain::scanIntervalDays());
        Settings::set('alerts.discovery_every_days', 30);

        // Passado o mês, o agendador libera (fila síncrona nos testes).
        $this->travel(30)->days();
        $this->artisan('secmon:alerts-discover', ['--due' => true])->expectsOutputToContain('1 domínio');
        Http::assertSentCount(4);

        // Só o operador, no terminal, força para teste.
        $this->artisan('secmon:alerts-discover', ['domain' => 'acmepay.com.br', '--force' => true])->expectsOutputToContain('acmepay.com.br: done');
        Http::assertSentCount(6);
    }

    public function test_each_scan_has_a_request_cap_and_never_asks_twice_for_a_page(): void
    {
        config(['secmon.alerts.discovery.max_requests' => 3]);
        $this->dns->set('acmepay.com.br', 'ips', ['104.21.1.1'])->set('www.acmepay.com.br', 'ips', ['104.21.1.2']);
        Http::fake([
            // A página de erro redireciona: o 302 já é a resposta (o cookie vem do app), sem seguir.
            'https://www.acmepay.com.br/secmon-check-*' => Http::response('', 302, ['Location' => 'https://www.acmepay.com.br/', 'Set-Cookie' => ['laravel_session=x; path=/']]),
            'https://acmepay.com.br/' => Http::response('', 301, ['Location' => 'https://www.acmepay.com.br/']),
            'https://www.acmepay.com.br/' => Http::response('<html><a href="/blog/">Blog</a></html>', 200),
            'https://www.acmepay.com.br/blog/' => Http::response('<html>blog</html>', 200),
        ]);
        $domain = $this->domain(['consent_version' => 'v2'], []);

        app(DiscoveryService::class)->run($domain);

        // 301 da home, a home e a página de erro: o blog ficou de fora pelo teto.
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/blog/'));
        $this->assertSame('cookie laravel_session', $domain->findings()->where('label', 'Laravel')->value('evidence'));
        $log = collect($domain->refresh()->last_log)->pluck('msg')->implode("\n");
        $this->assertStringContainsString('teto de 3 requisições por varredura: https://www.acmepay.com.br/blog/ não foi lido', $log);
        $this->assertStringContainsString('3 requisição(ões) ao site nesta varredura (teto 3)', $log);

        // Blog que redireciona para a home já lida: não é pedida de novo.
        config(['secmon.alerts.discovery.max_requests' => 5]);
        $this->dns->set('loja.com.br', 'ips', ['104.21.3.3']);
        Http::fake([
            'https://loja.com.br/secmon-check-*' => Http::response('', 404),
            'https://loja.com.br/blog/' => Http::response('', 301, ['Location' => 'https://loja.com.br/']),
            'https://loja.com.br/' => Http::response('<html><a href="https://loja.com.br/blog/">Blog</a></html>', 200),
        ]);
        $loja = $this->domain(['domain' => 'loja.com.br', 'consent_version' => 'v2'], []);
        app(DiscoveryService::class)->run($loja);

        // O Http::fake novo zera a contagem: home, página de erro e o 301 do blog.
        Http::assertSentCount(3);
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => $pair[0]->url() === 'https://loja.com.br/')->count());
        $this->assertStringContainsString('https://loja.com.br/ já foi lido nesta varredura: não pedimos de novo', collect($loja->refresh()->last_log)->pluck('msg')->implode("\n"));
    }

    /** Duas pessoas da mesma empresa, cada uma com o seu perfil: o site é lido uma vez só. */
    public function test_same_domain_in_another_profile_reuses_the_recent_scan(): void
    {
        $this->fakeSite();
        $first = $this->domain();
        app(DiscoveryService::class)->run($first);
        Http::assertSentCount(2);

        $second = $this->domain([], []);
        $this->assertTrue(app(DiscoveryService::class)->run($second));

        Http::assertSentCount(2);
        $second->refresh();
        $this->assertEqualsCanonicalizing($first->findings()->pluck('label')->all(), $second->findings()->pluck('label')->all());
        $this->assertSame('discovery', $second->profile->techs()->where('slug', 'sendgrid')->first()->pivot->source);
        $this->assertTrue($second->last_scan_at->equalTo($first->refresh()->last_scan_at));
        $this->assertStringContainsString('resultado reaproveitado, nenhuma requisição ao site', collect($second->last_log)->pluck('msg')->implode("\n"));
    }

    public function test_client_with_an_old_consent_is_asked_to_renew(): void
    {
        Queue::fake();
        $domain = $this->domain();
        $this->actingAs($domain->profile->user);

        $this->get(route('alerts.interview', 5))->assertOk()
            ->assertSee('Seu aceite é do termo v1, que cobre só a página inicial')
            ->assertSee('O termo atual é o')
            ->assertSee('a página de erro e o blog de', false);

        $this->travel(1)->minutes();
        $this->put(route('alerts.interview.update', 5), [
            'answers' => ['has_domain' => 'sim', 'domain' => 'acmepay.com.br'], 'consent' => '1', 'acao' => 'ficar',
        ])->assertSessionHasNoErrors();

        $domain->refresh();
        $this->assertSame('v3', $domain->consent_version);
        $this->assertTrue($domain->consent_at->isAfter(now()->subSeconds(5)));
        $this->assertTrue($domain->allowsMorePages());
        // Renovar não faz o site ser lido de novo: vale a partir da próxima varredura mensal.
        Queue::assertNothingPushed();
        $this->get(route('alerts.interview', 5))->assertDontSee('Seu aceite é do termo');
    }

    public function test_the_whole_page_is_read_up_to_the_cap(): void
    {
        $this->dns->set('acmepay.com.br', 'ips', ['104.21.1.1']);
        // ~940 KB de conteúdo antes da marca, bem além dos 512 KB de antes.
        $body = '<html><body>'.str_repeat('<p>conteúdo</p>', 60000).'<script src="https://js.hs-scripts.com/1.js"></script></body></html>';
        Http::fake(['https://acmepay.com.br/' => Http::response($body, 200)]);
        $domain = $this->domain();

        app(DiscoveryService::class)->run($domain);
        $this->assertTrue($domain->findings()->where('label', 'HubSpot')->exists(), 'marca no fim da página');
        $this->assertStringContainsString('KB lidos (página inteira)', collect($domain->refresh()->last_log)->pluck('msg')->implode("\n"));

        // Passou do teto: o resto não é baixado, e o log diz que cortou.
        config(['secmon.alerts.discovery.max_bytes' => 64 * 1024]);
        DomainFinding::query()->delete();
        $this->travel(30)->days();
        app(DiscoveryService::class)->run($domain->refresh());
        $this->assertFalse($domain->findings()->where('label', 'HubSpot')->exists());
        $this->assertStringContainsString('64 KB lidos (cortado no teto de 64 KB)', collect($domain->refresh()->last_log)->pluck('msg')->implode("\n"));
    }

    public function test_redirect_to_an_internal_address_is_blocked_before_any_request(): void
    {
        $this->dns->set('acmepay.com.br', 'ips', ['34.1.1.1'])->set('intranet.acmepay.com.br', 'ips', ['10.0.0.12']);
        Http::fake([
            'https://acmepay.com.br/' => Http::response('', 302, ['Location' => 'http://intranet.acmepay.com.br/admin']),
            '*' => Http::response('segredo'),
        ]);
        $domain = $this->domain();

        app(DiscoveryService::class)->run($domain);
        $domain->refresh();

        $this->assertSame('blocked', $domain->discovery_status);
        $this->assertStringContainsString('10.0.0.12 (rede privada)', $domain->last_error);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'intranet'));

        // Domínio que já resolve para metadata da nuvem: nem o primeiro request sai.
        Http::fake();
        $this->dns->set('cloud.com.br', 'ips', ['169.254.169.254']);
        $other = $this->domain(['domain' => 'cloud.com.br']);
        app(DiscoveryService::class)->run($other);

        $this->assertSame('blocked', $other->refresh()->discovery_status);
        Http::assertNothingSent();
    }

    public function test_other_ports_schemes_and_endless_redirects_are_refused(): void
    {
        $this->dns->set('acmepay.com.br', 'ips', ['34.1.1.1']);
        // Um stub só, em sequência: o primeiro stub registrado sempre ganha no Http::fake.
        Http::fake(['*' => Http::sequence()
            ->push('', 302, ['Location' => 'https://acmepay.com.br:8443/'])
            ->push('', 302, ['Location' => 'file:///etc/passwd'])
            ->push('', 302, ['Location' => '/a'])
            ->push('', 302, ['Location' => '/b'])
            ->push('', 302, ['Location' => '/c'])
            ->push('', 302, ['Location' => '/d'])
            ->push('', 302, ['Location' => '/loop'])
            ->push('', 302, ['Location' => '/loop'])]);
        $domain = $this->domain();

        app(DiscoveryService::class)->run($domain);
        $this->assertSame('blocked', $domain->refresh()->discovery_status, 'porta 8443');

        $this->travel(30)->days();
        app(DiscoveryService::class)->run($domain);
        $this->assertSame('blocked', $domain->refresh()->discovery_status, 'file://');

        $this->travel(30)->days();
        app(DiscoveryService::class)->run($domain);
        $this->assertStringContainsString('redirects demais', collect($domain->refresh()->last_log)->pluck('msg')->implode(' '));
        $this->assertSame('failed', $domain->discovery_status);

        // Loop: para na primeira URL repetida, sem gastar os 3 redirects.
        $this->travel(30)->days();
        app(DiscoveryService::class)->run($domain);
        $this->assertStringContainsString('https://acmepay.com.br/loop já foi lido nesta varredura', collect($domain->refresh()->last_log)->pluck('msg')->implode(' '));

        // 1 + 1 + (o original + 3 redirects) + 2; sem cair para http depois de "redirects demais".
        Http::assertSentCount(8);
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'http://') || str_contains($r->url(), ':8443'));
    }

    public function test_https_down_falls_back_to_http_once(): void
    {
        $this->dns->set('acmepay.com.br', 'ips', ['34.1.1.1']);
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'https://')) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response('<html></html>', 200, ['Server' => 'Apache/2.4.58']);
        });
        $domain = $this->domain();

        app(DiscoveryService::class)->run($domain);

        $this->assertSame('done', $domain->refresh()->discovery_status);
        $this->assertSame('Apache 2.4', $domain->findings()->value('label'));

        // O Http::fake não registra o request cujo stub lança; o log mostra as duas tentativas.
        $log = collect($domain->last_log)->pluck('msg')->implode("\n");
        $this->assertStringContainsString('GET https://acmepay.com.br/ → ', $log);
        $this->assertStringContainsString('GET http://acmepay.com.br/ → 200', $log);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://acmepay.com.br/');
    }

    public function test_nothing_is_queried_without_consent_and_proof(): void
    {
        Http::fake();

        foreach ([['verified_at' => null], ['consent_at' => null]] as $i => $missing) {
            $domain = $this->domain($missing + ['domain' => "sem{$i}.com.br"]);
            app(DiscoveryService::class)->run($domain);
            $this->assertSame('pending', $domain->refresh()->discovery_status);
        }

        Http::assertNothingSent();
        $this->assertSame([], $this->dns->asked);

        Settings::set('alerts.discovery_enabled', false);
        $this->fakeSite();
        $domain = $this->domain(['domain' => 'acmepay.com.br']);
        DiscoverDomainStack::dispatchSync($domain->id);
        $this->assertSame('pending', $domain->refresh()->discovery_status);
    }

    public function test_what_is_found_goes_straight_into_the_profile_and_removals_stick(): void
    {
        $this->fakeSite();
        $domain = $this->domain([], ['nginx', 'cloudflare']);
        $profile = $domain->profile;
        $profile->update(['company_name' => null, 'include_brand_mentions' => false]);

        app(DiscoveryService::class)->run($domain);
        $profile->refresh();

        // Sem revisão: tudo o que foi reconhecido já está no perfil e na busca.
        $sources = $profile->techs()->pluck('alert_profile_techs.source', 'slug')->all();
        $this->assertSame('interview', $sources['nginx'], 'o que veio da entrevista continua da entrevista');
        $this->assertSame('interview', $sources['cloudflare']);
        foreach (['ubuntu', 'linux', 'php', 'laravel', 'google-workspace', 'sendgrid', 'hubspot'] as $slug) {
            $this->assertSame('discovery', $sources[$slug] ?? null, $slug);
        }
        $this->assertSame(70, $profile->techs()->where('slug', 'sendgrid')->first()->pivot->confidence);
        $this->assertStringContainsString('"sendgrid"', $profile->match_query);
        $this->assertSame(9, $domain->findings()->where('status', 'accepted')->count());
        $this->assertStringContainsString('7 adicionada(s) ao perfil', collect($domain->refresh()->last_log)->last()['msg']);

        $user = $profile->user;
        $this->actingAs($user)->get(route('alerts.interview', 5))->assertOk()
            ->assertSee('Identificamos 9 tecnologias em acmepay.com.br')
            ->assertSee('já estava no perfil')
            ->assertSee('Remover')
            ->assertDontSee('Ignorar')
            ->assertSee('De brinde:');
        $this->get(route('alerts.news'))->assertSee('9 tecnologias identificadas, todas no perfil')->assertDontSee('Revisar');

        // Remover tira do perfil e da busca, e a próxima descoberta não devolve.
        $hubspot = DomainFinding::query()->where('label', 'HubSpot')->firstOrFail();
        $this->post(route('alerts.finding.decide', [$hubspot, 'ignorar']))->assertSessionHas('status');
        $this->assertFalse($profile->techs()->where('slug', 'hubspot')->exists());
        $this->assertStringNotContainsString('"hubspot"', $profile->refresh()->match_query);

        $this->travel(30)->days();
        app(DiscoveryService::class)->run($domain->refresh());
        $this->assertSame('ignored', $hubspot->refresh()->status);
        $this->assertFalse($profile->techs()->where('slug', 'hubspot')->exists());
        $this->get(route('alerts.interview', 5))->assertSee('Adicionar de volta');

        // O que veio da entrevista não sai por aqui.
        $nginx = DomainFinding::query()->where('label', 'Nginx 1.24')->firstOrFail();
        $this->post(route('alerts.finding.decide', [$nginx, 'ignorar']));
        $this->assertTrue($profile->techs()->where('slug', 'nginx')->exists());

        // "Adicionar de volta" desfaz a remoção.
        $this->post(route('alerts.finding.decide', [$hubspot, 'aceitar']));
        $this->assertSame('discovery', $profile->techs()->where('slug', 'hubspot')->first()->pivot->source);
        $this->assertSame('accepted', $hubspot->refresh()->status);

        // Ninguém mexe nos achados dos outros.
        $this->actingAs($this->client())->post(route('alerts.finding.decide', [$hubspot, 'ignorar']))->assertNotFound();
        $this->assertSame('accepted', $hubspot->refresh()->status);

        // Trocar de domínio leva junto o que o domínio antigo pôs no perfil.
        app(InterviewService::class)->save($profile->refresh(), 5, ['has_domain' => 'sim', 'domain' => 'novodominio.com.br'], ['consent' => true]);
        $this->assertFalse($profile->techs()->wherePivot('source', 'discovery')->exists());
        $this->assertStringNotContainsString('"sendgrid"', $profile->refresh()->match_query);
        $this->assertSame(0, DomainFinding::query()->count());
    }

    public function test_due_command_and_admin_pages(): void
    {
        Queue::fake();
        $due = $this->domain(['next_run_at' => now()->subDay()]);
        $this->domain(['domain' => 'futuro.com.br', 'next_run_at' => now()->addDays(5)]);
        $this->domain(['domain' => 'semposse.com.br', 'verified_at' => null]);

        $this->artisan('secmon:alerts-discover', ['--due' => true])->expectsOutputToContain('1 domínio');
        Queue::assertPushed(DiscoverDomainStack::class, 1);
        Queue::assertPushed(DiscoverDomainStack::class, fn ($job) => $job->domainId === $due->id);

        $due->forceFill(['discovery_status' => 'blocked', 'last_error' => 'intranet aponta para 10.0.0.12 (rede privada)', 'last_log' => [['t' => now()->toIso8601String(), 'msg' => 'bloqueado', 'level' => 'error']]])->save();
        $this->actingAs($this->adminUser());

        $this->get(route('admin.alerts.domains'))->assertOk()->assertSee('acmepay.com.br')->assertSee('bloqueada')->assertSee('aguardando TXT');
        $this->get(route('admin.alerts.domains', ['log' => $due->id]))->assertSee('Última execução')->assertSee('bloqueado');
        $this->get(route('admin.alerts.overview'))->assertSee('rede privada');

        $this->post(route('admin.alerts.domains.run', $due))->assertRedirect(route('admin.alerts.domains', ['log' => $due->id]));
        $noProof = MonitoredDomain::query()->where('domain', 'semposse.com.br')->first();
        $this->post(route('admin.alerts.domains.run', $noProof))->assertSessionHas('error');
    }
}
