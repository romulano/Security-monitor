<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Tests\TestCase;

/**
 * A tabela de rotas como produção a usa. Os testes rodam com as rotas sem
 * cache, e aí, entre duas rotas no mesmo endereço e método, vale a última;
 * com `route:cache` (produção e o ambiente local) vale a primeira. Foi assim
 * que o redirecionamento da antiga Central (Route::redirect, que atende todos
 * os métodos) engoliu o "Salvar o que cada app recebe" de Notificadores.
 */
class RouteTableTest extends TestCase
{
    public function test_no_two_routes_answer_the_same_method_on_the_same_address(): void
    {
        $seen = [];

        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $name = $route->getName() ?? $route->getActionName();

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = $method.' '.$route->getDomain().'/'.$route->uri();
                $this->assertArrayNotHasKey($key, $seen, $key.' tem duas rotas: '.($seen[$key] ?? '').' e '.$name);
                $seen[$key] = $name;
            }
        }
    }

    /**
     * Com login, a chave de um `throttle:N,M` é só a conta: sem um prefixo (o 3º parâmetro), todos os
     * limites dela somam no mesmo contador, e um gasta o outro. Toda rota com login tem o seu contador,
     * sem repetir. Limitador com nome (`throttle:busca`) já tem a chave dele.
     */
    public function test_every_limit_behind_a_login_has_its_own_counter(): void
    {
        $counters = [];

        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $middleware = array_filter($route->gatherMiddleware(), 'is_string');

            if (! in_array('auth', $middleware, true)) {
                continue;
            }

            foreach ($middleware as $item) {
                $params = str_starts_with($item, 'throttle:') ? explode(',', substr($item, strlen('throttle:'))) : [];

                if (count($params) < 2) {
                    continue;
                }

                $this->assertCount(3, $params, ($route->getName() ?? $route->uri()).": {$item} divide o contador com os outros limites da conta");
                $this->assertArrayNotHasKey($params[2], $counters, "o contador {$params[2]} está em ".($counters[$params[2]] ?? '').' e em '.$route->getName());
                $counters[$params[2]] = $route->getName();
            }
        }

        $this->assertGreaterThan(35, count($counters));
    }

    public function test_notifiers_choices_and_pause_reach_their_controllers_with_cached_routes(): void
    {
        $router = $this->app['router'];
        ['compiled' => $compiled, 'attributes' => $attributes] = $router->getRoutes()->compile();
        $cached = (new CompiledRouteCollection($compiled, $attributes))->setRouter($router)->setContainer($this->app);

        $this->assertSame('alerts.center.update', $cached->match(Request::create('/alertas/central', 'PUT'))->getName());
        $this->assertSame('alerts.center.pause', $cached->match(Request::create('/alertas/central/1/pausar', 'POST'))->getName());
        $this->assertSame('alerts.center', $cached->match(Request::create('/alertas/central', 'GET'))->getName());
    }
}
