<?php

namespace Tests\Feature\Company;

use App\Models\Contracts\VisibilityRoot;
use App\Models\Contracts\VisibleThroughRoot;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * DESENHO-5.2 §4.4, opção D (o esqueleto): toda rota que recebe pela URL um item da visibilidade (raiz ou filho) está
 * classificada aqui — quem pode abrir (`ver`: quem vê; `configurar`: admin e responsável do setor; `assinado`: o link
 * da mensagem, sem login; `admin`: o admin do Security Monitor) e a PR que a passa para o Visibility. Rota nova com um desses
 * itens derruba o teste até ser classificada. As PRs E2c1 em diante acrescentam, para cada rota delas, a visita como conta de outro setor e de outra
 * empresa esperando 404 (VisibilityFixtures).
 */
class VisibilityRouteMapTest extends TestCase
{
    /** "MÉTODO uri" => [quem pode, PR que passa para o Visibility]. */
    private const ROUTES = [
        'GET a/{match}/nao-relevante' => ['assinado', 'E2e'],
        'GET a/{match}/relevante' => ['assinado', 'E2e'],
        'POST a/{match}/motivo' => ['assinado', 'E2e'],
        'GET g/{finding}/{acao}' => ['assinado', 'E2e'],
        'POST g/{finding}/motivo' => ['assinado', 'E2e'],
        'GET r/foto/{alert}' => ['assinado', 'E2e'],
        'GET r/{lookalike}/{acao}' => ['assinado', 'E2e'],
        'POST r/{lookalike}/motivo' => ['assinado', 'E2e'],
        'POST admin/alertas/dominios/{domain}/rodar' => ['admin', 'fica: admin do Security Monitor'],
        'POST alertas/{match}/feedback' => ['ver', 'E2c1'],
        'POST alertas/{match}/reenviar' => ['configurar', 'E2c1'],
        'POST alertas/sugestoes/{finding}/{acao}' => ['configurar', 'E2c1'],
        'GET alertas/radar-de-dominios/{lookalike}' => ['ver', 'E2d1'],
        'GET alertas/radar-de-dominios/{lookalike}/denuncia' => ['ver', 'E2d1'],
        'POST alertas/radar-de-dominios/{lookalike}/avaliar' => ['ver', 'E2d1'],
        // Marcar onde denunciou é registro de uma ação de fora, como o voto (DESENHO-5.2 §2.1).
        'POST alertas/radar-de-dominios/{lookalike}/denuncia/{canal}' => ['ver', 'E2d1'],
        'GET alertas/radar-de-dominios/print/{capture}/{aparelho}/{momento?}' => ['ver', 'E2d1'],
        'GET alertas/radar-de-dominios/referencia/{watch}/{pagina}/{aparelho}' => ['ver', 'E2d1'],
        'GET alertas/github/{finding}' => ['ver', 'E2d2'],
        'POST alertas/github/{finding}/avaliar' => ['ver', 'E2d2'],
        'POST alertas/github/{finding}/enviar' => ['configurar', 'E2d2'],
        // Domínios da empresa: comprovar e trocar o setor são de quem configura (remover leva o id no corpo, com a reauth).
        'POST empresa/dominios/{domain}/verificar' => ['configurar', 'E3a3'],
        'PUT empresa/dominios/{domain}/setor' => ['configurar', 'E3a3'],
        'POST empresa/dominios/{domain}/subdominios' => ['configurar', 'E3b'],
        'DELETE empresa/subdominios/{subdomain}' => ['configurar', 'E3b'],
        // O canal de aviso (E5a1): pausar é de quem configura o escopo (Visibility::manages), não de quem conectou.
        'POST alertas/central/{channel}/pausar' => ['configurar', 'E5a1'],
    ];

    public function test_every_route_that_takes_a_monitored_item_is_classified(): void
    {
        $found = [];

        foreach (Router::getRoutes() as $route) {
            /** @var Route $route */
            if (($models = $this->monitoredParameters($route)) === []) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $found[$method.' '.$route->uri()] = $models;
                }
            }
        }

        ksort($found);
        $expected = array_map(fn () => true, self::ROUTES);
        ksort($expected);

        $this->assertSame(array_keys($expected), array_keys($found), "Rota com item da visibilidade sem classificação (ou classificada e que já não existe). Achadas:\n".var_export($found, true));

        foreach (self::ROUTES as $route => [$who]) {
            $uri = explode(' ', $route, 2)[1];
            // O link da mensagem é o /a/, /r/ ou /g/; o admin do Security Monitor, o /admin; o resto pede login de quem vê.
            $allowed = match (true) {
                (bool) preg_match('#^[arg]/#', $uri) => ['assinado'],
                str_starts_with($uri, 'admin/') => ['admin'],
                default => ['ver', 'configurar'],
            };
            $this->assertContains($who, $allowed, $route);
        }
    }

    /** @return list<string> os modelos da visibilidade que o controller recebe pela rota */
    private function monitoredParameters(Route $route): array
    {
        $controller = $route->getControllerClass();

        if ($controller === null || ! method_exists($controller, '__invoke')) {
            return [];
        }

        $models = [];

        foreach ((new ReflectionMethod($controller, '__invoke'))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()
                && (is_subclass_of($type->getName(), VisibilityRoot::class) || is_subclass_of($type->getName(), VisibleThroughRoot::class))) {
                $models[] = class_basename($type->getName());
            }
        }

        return $models;
    }
}
