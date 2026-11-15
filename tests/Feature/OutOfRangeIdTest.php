<?php

namespace Tests\Feature;

use App\Http\Middleware\RejectOutOfRangeIds;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * Id grande demais para a coluna (30 dígitos) na URL: o Postgres recusa com 22003 e era 500 em toda rota com binding
 * numérico (Red Team do 5.2). Agora é 404, como o id que não existe, antes de qualquer consulta (RejectOutOfRangeIds).
 */
class OutOfRangeIdTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private const HUGE = '123456789012345678901234567890';

    protected function setUp(): void
    {
        parent::setUp();

        // Uma rota que passar do binding não pode sair da máquina nem rodar job.
        Http::fake();
        Queue::fake();
        Bus::fake();
    }

    /** @return array<string, array{0: string, 1: string}> "MÉTODO uri" => [método, endereço com o id enorme] */
    private function numericRoutes(): array
    {
        $out = [];

        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $bound = array_map(fn ($parameter) => $parameter->getName(), $route->signatureParameters(['subClass' => Model::class]));
            $numeric = array_keys(array_filter($route->wheres, fn ($pattern) => str_contains($pattern, '[0-9]')));
            $targets = array_intersect($route->parameterNames(), [...$bound, ...$numeric]);

            if ($targets === []) {
                continue;
            }

            $uri = preg_replace_callback('/\{([^}?]+)\??\}/', function ($match) use ($route, $targets) {
                $pattern = $route->wheres[$match[1]] ?? null;

                return match (true) {
                    in_array($match[1], $targets, true) => self::HUGE,
                    $pattern === null => 'x',
                    str_contains($pattern, '{8}-') => '00000000-0000-4000-8000-000000000000',
                    default => stripslashes(explode('|', trim($pattern, '()'))[0]),
                };
            }, $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $out[$method.' '.$route->uri()] = [$method, '/'.ltrim($uri, '/')];
            }
        }

        return $out;
    }

    public function test_no_route_with_a_numeric_id_answers_500_to_an_id_beyond_bigint(): void
    {
        [, $companyAdmin] = $this->activeCompany('Acme', 'admin@acme.com.br');
        $secmonAdmin = User::factory()->create(['is_admin' => true]);
        $routes = $this->numericRoutes();
        $this->assertGreaterThan(40, count($routes));

        foreach (['visitante' => null, 'admin da empresa' => $companyAdmin, 'admin do Security Monitor' => $secmonAdmin] as $who => $user) {
            $user ? $this->actingAs($user) : auth()->logout();

            foreach ($routes as $key => [$method, $url]) {
                $this->assertLessThan(500, $this->call($method, $url)->status(), "$key como $who");
            }
        }
    }

    public function test_the_routes_seen_by_the_red_team_answer_404(): void
    {
        [, $companyAdmin] = $this->activeCompany('Acme', 'admin@acme.com.br');
        $id = self::HUGE;

        $this->actingAs($companyAdmin);
        $this->put("/empresa/setores/$id", ['nome' => 'TI'])->assertNotFound();
        $this->delete("/empresa/setores/1/contas/$id")->assertNotFound();
        $this->put("/empresa/contas/$id/papel", ['papel' => 'member'])->assertNotFound();
        $this->post("/alertas/$id/feedback")->assertNotFound();
        $this->get("/alertas/radar-de-dominios/$id")->assertNotFound();
        $this->get("/alertas/github/$id")->assertNotFound();
        // O mesmo no JSON, e com o sinal.
        $this->getJson("/alertas/radar-de-dominios/$id")->assertNotFound();
        $this->get("/alertas/radar-de-dominios/-$id")->assertNotFound();

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get("/admin/clientes/$id")->assertNotFound();
        $this->post("/admin/usuarios/$id/testes")->assertNotFound();
    }

    public function test_the_limit_is_the_bigint(): void
    {
        foreach (['9223372036854775807', '0000000000009223372036854775807', '-9223372036854775807', '1', '2147483648'] as $fits) {
            $this->assertFalse(RejectOutOfRangeIds::outOfRange($fits), $fits);
        }

        foreach (['9223372036854775808', '10000000000000000000', '-'.self::HUGE, self::HUGE] as $huge) {
            $this->assertTrue(RejectOutOfRangeIds::outOfRange($huge), $huge);
        }

        // Só dígitos: o resto (uuid, nome de domínio, hash) segue para a rota como antes.
        $this->assertFalse(RejectOutOfRangeIds::outOfRange(self::HUGE.'a'));
        $this->assertFalse(RejectOutOfRangeIds::outOfRange('1e30'));
    }
}
