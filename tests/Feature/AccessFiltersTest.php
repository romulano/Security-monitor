<?php

namespace Tests\Feature;

use App\Models\PageView;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Admin → Acessos: a barra de filtros (período, quem, "Mais filtros" e chips) e os filtros que não se contradizem. */
class AccessFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        PageView::query()->delete();
        $this->travelTo(Carbon::parse('2026-11-04 12:00:00', 'UTC'));
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function visit(string $ip, string $path, array $columns = []): PageView
    {
        return PageView::query()->create($columns + [
            'token' => (string) Str::uuid(), 'ip' => $ip, 'user_agent' => 'Mozilla/5.0 Firefox/144.0', 'path' => $path,
            'is_bot' => false, 'status' => 200, 'duration_ms' => 30_000, 'created_at' => now()->subHour(),
        ]);
    }

    public function test_origins_ignore_the_robot_filters_instead_of_going_empty(): void
    {
        $this->visit('203.0.113.7', '/sobre', ['referer' => 'https://bsky.app/profile/x']);
        $this->visit('198.51.100.9', '/', ['is_bot' => true, 'bot_kind' => 'search', 'bot_owner' => 'Google', 'duration_ms' => null]);

        foreach ([['dono' => 'Google'], ['tipo' => 'search'], ['pagina' => '/casos']] as $filter) {
            $page = $this->get(route('admin.accesses.origins', $filter))->assertOk();
            $this->assertSame(['bsky.app'], $page->viewData('referrers')->pluck('domain')->all(), json_encode($filter));
        }
    }

    public function test_the_page_filter_is_exact_and_a_star_makes_it_a_prefix(): void
    {
        $this->visit('203.0.113.7', '/');
        $this->visit('203.0.113.7', '/?q=ransomware');
        $this->visit('203.0.113.7', '/cadastro');
        $this->visit('203.0.113.7', '/cadastro/confirmar/x');
        $this->visit('203.0.113.7', '/sobre');

        $paths = fn (string $page) => $this->get(route('admin.accesses', ['pagina' => $page]))->assertOk()
            ->viewData('recent')->getCollection()->pluck('path')->sort()->values()->all();

        $this->assertSame(['/', '/?q=ransomware'], $paths('/'));
        $this->assertSame(['/cadastro'], $paths('/cadastro'));
        $this->assertSame(['/cadastro', '/cadastro/confirmar/x'], $paths('/cadastro*'));
    }

    public function test_an_invalid_ip_is_refused_with_a_message(): void
    {
        $this->get(route('admin.accesses', ['ip' => 'abc']))->assertSessionHasErrors(['ip' => 'IP inválido.']);
        $this->get(route('admin.accesses.attacks', ['ip' => 'abc']))->assertSessionHasErrors('ip');
        $this->get(route('admin.accesses', ['ip' => '2804:14c::1']))->assertOk();
    }

    public function test_the_tabs_carry_only_the_common_filters_without_the_defaults(): void
    {
        $this->get(route('admin.accesses'))->assertOk()
            ->assertDontSee('periodo=24h', false)
            ->assertDontSee('robos=com', false)
            ->assertDontSee('nossos=esconder', false);

        // A ida e volta por Ataques: país e nossos vão; a categoria, que é de Ataques, fica lá.
        $this->get(route('admin.accesses.attacks', ['pais' => 'BR', 'nossos' => 'mostrar', 'categoria' => 'sqli']))->assertOk()
            ->assertSee('href="'.e(route('admin.accesses', ['pais' => 'BR', 'nossos' => 'mostrar'])).'"', false)
            ->assertDontSee('/acessos?pais=BR&amp;nossos=mostrar&amp;categoria', false);

        // Os da aba (quem, página) não passam para as outras abas; a ficha do IP leva só o IP (abre nos 180 dias).
        $this->get(route('admin.accesses', ['ip' => '203.0.113.7', 'periodo' => '7d', 'robos' => 'sem', 'pagina' => '/sobre']))->assertOk()
            ->assertSee('href="'.e(route('admin.accesses.robots', ['periodo' => '7d', 'ip' => '203.0.113.7'])).'"', false)
            ->assertSee('href="'.e(route('admin.accesses.ip', ['ip' => '203.0.113.7'])).'"', false);
    }

    public function test_the_active_filters_become_chips_with_a_way_out(): void
    {
        $this->visit('203.0.113.7', '/sobre', ['country' => 'BR']);

        $this->get(route('admin.accesses', ['pais' => 'br', 'pagina' => '/<script>alert(1)</script>', 'robos' => 'sem']))->assertOk()
            ->assertSee('País BR')
            ->assertSee('aria-label="Tirar o filtro País BR"', false)
            // O × tira só o país: o resto do recorte fica.
            ->assertSee('href="'.e(route('admin.accesses', ['robos' => 'sem', 'pagina' => '/<script>alert(1)</script>'])).'"', false)
            ->assertSee('Página /&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Mais filtros')
            ->assertSee('Limpar');

        // "Pessoas" tira tipo e dono (pessoa não tem robô); "Robôs" e o período mantêm.
        $this->get(route('admin.accesses', ['dono' => 'Google', 'tipo' => 'search']))->assertOk()
            ->assertSee('href="'.e(route('admin.accesses', ['robos' => 'sem'])).'"', false)
            ->assertSee('href="'.e(route('admin.accesses', ['tipo' => 'search', 'dono' => 'Google', 'robos' => 'so'])).'"', false)
            ->assertSee('href="'.e(route('admin.accesses', ['tipo' => 'search', 'dono' => 'Google', 'periodo' => '7d'])).'"', false);
    }

    public function test_the_robots_tab_has_no_who_switch_and_links_the_robot_to_the_overview(): void
    {
        $this->visit('198.51.100.9', '/', ['is_bot' => true, 'bot_kind' => 'search', 'bot_owner' => 'Google', 'duration_ms' => null, 'country' => 'BR']);

        $this->get(route('admin.accesses.robots', ['pais' => 'BR']))->assertOk()
            ->assertDontSee('aria-label="Quem"', false)
            ->assertSee('href="'.e(route('admin.accesses.robots', ['pais' => 'BR', 'tipo' => 'search', 'dono' => 'Google'])).'"', false)
            ->assertSee('href="'.e(route('admin.accesses', ['tipo' => 'search', 'dono' => 'Google', 'pais' => 'BR'])).'"', false);
    }
}
