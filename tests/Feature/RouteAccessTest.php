<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quem pode abrir cada rota. Três travas, todas sobre a tabela de rotas real:
 *
 *  - nenhuma rota fica aberta por esquecimento: o que não exige login, admin ou
 *    assinatura está na lista PUBLIC abaixo, e rota nova fora dela derruba o teste;
 *  - toda rota pública que grava algo tem limite de requisições;
 *  - as rotas do admin recusam o visitante (vai para o login do admin) e o
 *    usuário comum (403 ou 404), sem executar nada.
 */
class RouteAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Abertas a qualquer visitante, de propósito. */
    private const PUBLIC = [
        'GET .well-known/security.txt',
        'GET /',
        // "Ler no Security Monitor" da mensagem do Slack: só leva à matéria, que já é pública.
        'GET a/materia/{uuid}',
        'GET admin/login',
        'GET api/news',
        'GET api/news/case/{uuid}',
        'GET api/news/dashboard',
        'GET api/news/updates',
        'GET cadastro',
        'GET dashboard',
        // O convite da empresa (CompanyInvites): o token de 48 caracteres, só com hash no banco, substitui o login.
        'GET empresa/convite/{token}',
        'GET idioma/{lang}',
        'GET login',
        // O código da 2FA: sem a senha certa nesta sessão, volta ao login (TwoFactorChallenge).
        'GET login/codigo',
        'GET login/github',
        'GET login/github/callback',
        'GET materia/{uuid}',
        'GET privacidade',
        'GET security.txt',
        'GET sitemap-{part}.xml',
        'GET sitemap.xml',
        'GET sobre',
        'GET storage/{path}',
        'GET termos',
        'GET up',
        'POST acessos/tempo',
        'POST admin/login',
        'POST cadastro',
        'POST cadastro/github',
        'POST empresa/convite/{token}',
        'POST login',
        'POST login/codigo',
        'PUT storage/{path}',
    ];

    /** Rota do próprio framework (disco local), que só aceita endereço assinado. */
    private const FRAMEWORK_UPLOAD = 'PUT storage/{path}';

    protected function setUp(): void
    {
        parent::setUp();

        // Se uma trava falhar, a rota recusada não pode sair da máquina nem rodar job.
        Http::fake();
        Queue::fake();
        Bus::fake();
    }

    /** @return list<array{0: string, 1: Route}> método e rota (sem HEAD) */
    private function routes(): array
    {
        $out = [];

        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $out[] = [$method, $route];
            }
        }

        return $out;
    }

    private function key(string $method, Route $route): string
    {
        return $method.' '.($route->uri() === '/' ? '/' : $route->uri());
    }

    private function middleware(Route $route): array
    {
        return array_map(fn ($m) => is_string($m) ? $m : 'closure', $route->gatherMiddleware());
    }

    private function protected(Route $route): bool
    {
        return (bool) array_intersect(['auth', 'admin', 'signed'], $this->middleware($route));
    }

    /** O endereço da rota com um valor que passa nas restrições (whereNumber, whereIn) de cada parâmetro. */
    private function url(Route $route): string
    {
        $uri = preg_replace('/\{[^}]+\?\}/', '', $route->uri());

        $uri = preg_replace_callback('/\{([^}]+)\}/', function ($match) use ($route) {
            $pattern = $route->wheres[$match[1]] ?? null;

            return match (true) {
                $pattern === null || str_contains($pattern, '[0-9]') => '1',
                // whereUuid (o número de uma execução dos testes de segurança): um uuid que passa no formato.
                str_contains($pattern, '{8}-') => '00000000-0000-4000-8000-000000000000',
                default => stripslashes(explode('|', $pattern)[0]),
            };
        }, $uri);

        return '/'.ltrim($uri, '/');
    }

    public function test_only_the_known_routes_are_open_to_visitors(): void
    {
        $open = [];

        foreach ($this->routes() as [$method, $route]) {
            if (! $this->protected($route)) {
                $open[] = $this->key($method, $route);
            }
        }

        sort($open);
        $expected = self::PUBLIC;
        sort($expected);

        $this->assertSame(
            $expected,
            $open,
            'A lista de rotas abertas mudou. Rota nova sem auth/admin/signed precisa entrar em PUBLIC de forma consciente; rota que saiu da lista, sair daqui.'
        );
    }

    public function test_every_open_route_that_writes_has_a_rate_limit(): void
    {
        $without = [];

        foreach ($this->routes() as [$method, $route]) {
            $key = $this->key($method, $route);

            if (in_array($method, ['GET'], true) || in_array('auth', $this->middleware($route), true)
                || in_array('admin', $this->middleware($route), true) || $key === self::FRAMEWORK_UPLOAD) {
                continue;
            }

            $limited = array_filter($this->middleware($route), fn ($m) => str_starts_with($m, 'throttle:'));

            if ($limited === []) {
                $without[] = $key;
            }
        }

        $this->assertSame([], $without, "Rotas que gravam e não têm limite de requisições:\n".implode("\n", $without));
    }

    public function test_visitors_are_sent_to_the_admin_login_on_every_admin_route(): void
    {
        $checked = 0;

        foreach ($this->routes() as [$method, $route]) {
            if (! in_array('admin', $this->middleware($route), true)) {
                continue;
            }

            $response = $this->call($method, $this->url($route));

            $this->assertTrue(
                $response->isRedirect(route('admin.login')),
                $this->key($method, $route).' respondeu '.$response->getStatusCode().' a um visitante'
            );
            $checked++;
        }

        $this->assertGreaterThan(50, $checked, 'A matriz do admin deveria cobrir dezenas de rotas.');
    }

    public function test_common_users_are_refused_on_every_admin_route(): void
    {
        $user = User::factory()->create();
        $checked = 0;

        foreach ($this->routes() as [$method, $route]) {
            if (! in_array('admin', $this->middleware($route), true)) {
                continue;
            }

            $response = $this->actingAs($user)->call($method, $this->url($route));

            $this->assertContains(
                $response->getStatusCode(),
                [403, 404],
                $this->key($method, $route).' respondeu '.$response->getStatusCode().' a um usuário comum'
            );
            $checked++;
        }

        $this->assertGreaterThan(50, $checked);
    }

    public function test_visitors_are_sent_to_the_login_on_every_user_area_route(): void
    {
        $checked = 0;

        foreach ($this->routes() as [$method, $route]) {
            $middleware = $this->middleware($route);

            if (! in_array('auth', $middleware, true) || in_array('admin', $middleware, true)) {
                continue;
            }

            $response = $this->call($method, $this->url($route));

            $this->assertTrue(
                $response->isRedirect(route('login')),
                $this->key($method, $route).' respondeu '.$response->getStatusCode().' a um visitante'
            );
            $checked++;
        }

        $this->assertGreaterThan(50, $checked);
    }

    public function test_signed_links_without_a_valid_signature_are_refused(): void
    {
        $checked = 0;

        foreach ($this->routes() as [$method, $route]) {
            if (! in_array('signed', $this->middleware($route), true)) {
                continue;
            }

            $response = $this->call($method, $this->url($route));
            // Atrás de uma chave desligada (o plano empresarial), o 404 vem antes de tudo, até da assinatura.
            $expected = in_array('enterprise', $this->middleware($route), true) ? 404 : 403;

            $this->assertSame($expected, $response->getStatusCode(), $this->key($method, $route).' aceitou um link sem assinatura');
            $checked++;
        }

        $this->assertGreaterThan(5, $checked);
    }

    public function test_a_malformed_case_id_is_a_404_and_not_a_database_error(): void
    {
        // O Postgres recusa um valor que não é uuid na coluna uuid: sem a restrição da rota, era 500.
        foreach (['/materia/abc', '/materia/1', '/api/news/case/abc', '/api/news/case/1%27%20OR%201=1'] as $url) {
            $this->get($url)->assertNotFound();
        }

        $this->get('/materia/'.(string) Str::uuid())->assertNotFound();
        $this->getJson('/api/news/case/'.(string) Str::uuid())->assertNotFound();
    }
}
