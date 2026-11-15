<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\PageView;
use App\Models\User;
use App\Services\Admin\AccessReport;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin → Acessos: pessoa é o IP que, no período, ficou HUMAN_MIN_MS (1 s) ou
 * mais numa página, sem ser robô declarado, nosso nem atacante. Uma definição
 * só nas barras, no cartão, nos filtros, no selo e na aba Origens.
 */
class AccessPeopleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        PageView::query()->delete();
    }

    private function visit(string $ip, ?int $duration, array $columns = []): void
    {
        PageView::query()->create($columns + [
            'token' => (string) Str::uuid(), 'ip' => $ip, 'user_agent' => 'Mozilla/5.0 Firefox/144.0', 'path' => '/sobre',
            'referer' => 'https://bsky.app/profile/x', 'is_bot' => false, 'status' => 200, 'duration_ms' => $duration,
            'created_at' => now()->subMinutes(30),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_one_second_on_a_page_is_the_line(): void
    {
        $this->assertSame(1000, AccessReport::HUMAN_MIN_MS);
        $this->visit('203.0.113.10', 999);
        $this->visit('203.0.113.11', 1000);

        $page = $this->actingAs($this->admin())->get(route('admin.accesses'))->assertOk();
        $byIp = $page->viewData('byIp')->keyBy('ip');

        $this->assertTrue($byIp['203.0.113.10']->likely_bot);
        $this->assertFalse($byIp['203.0.113.11']->likely_bot);
        $this->assertSame(1, (int) $page->viewData('totals')->people);
        $this->assertSame(['203.0.113.11'], $this->get(route('admin.accesses', ['robos' => 'sem']))->viewData('byIp')->pluck('ip')->all());
    }

    public function test_the_same_definition_in_the_bars_the_filters_the_card_and_origins(): void
    {
        // A pessoa: 3 s numa página e, agora, outra aberta cujo tempo ainda não chegou (conta: a regra é por IP).
        $this->visit('203.0.113.7', 3000, ['path' => '/sobre?utm_source=bluesky']);
        $this->visit('203.0.113.7', null, ['path' => '/casos', 'referer' => 'https://outro.example/', 'created_at' => now()->subMinute()]);
        // Ficou menos de 1 s.
        $this->visit('203.0.113.8', 999, ['referer' => 'https://rapido.example/']);
        // Com tempo, mas fora: robô declarado, nosso, atacante e quem varre 404.
        $this->visit('198.51.100.9', 5000, ['is_bot' => true, 'bot_kind' => 'search', 'bot_owner' => 'Google', 'referer' => 'https://robo.example/']);
        $this->visit('127.0.0.1', 5000, ['referer' => 'https://nosso.example/']);
        $this->visit('198.51.100.30', 5000, ['referer' => 'https://evil.example/']);
        AccessEvent::query()->create(['created_at' => now()->subMinutes(30), 'ip' => '198.51.100.30', 'method' => 'GET', 'path' => "/?id=1' or 1=1--", 'status' => 200, 'category' => 'sqli']);
        foreach (['/contact', '/imprint', '/about-us', '/legal', '/team'] as $path) {
            $this->visit('198.51.100.20', 5000, ['path' => $path, 'status' => 404, 'referer' => 'https://scan.example/']);
        }

        $this->actingAs($this->admin());
        $all = $this->get(route('admin.accesses', ['nossos' => 'mostrar']))->assertOk();
        $totals = $all->viewData('totals');
        $series = $all->viewData('series');

        // O cartão "Pessoas", as barras e os dois lados do filtro Robôs contam o mesmo.
        $this->assertSame(1, (int) $totals->people);
        $this->assertSame(2, $series->sum('people'));
        $this->assertSame(9, $series->sum('robots'));
        $this->assertSame(9, (int) $totals->bots);
        $this->assertFalse($all->viewData('byIp')->keyBy('ip')['203.0.113.7']->likely_bot);

        $people = $this->get(route('admin.accesses', ['nossos' => 'mostrar', 'robos' => 'sem']))->assertOk();
        $this->assertSame(['203.0.113.7'], $people->viewData('byIp')->pluck('ip')->unique()->values()->all());
        $this->assertSame(2, $people->viewData('recent')->total());
        $this->assertSame([2, 0], [$people->viewData('series')->sum('people'), $people->viewData('series')->sum('robots')]);

        $robots = $this->get(route('admin.accesses', ['nossos' => 'mostrar', 'robos' => 'so']))->assertOk();
        $this->assertSame(9, $robots->viewData('recent')->total());
        $this->assertNotContains('203.0.113.7', $robots->viewData('byIp')->pluck('ip')->all());

        // Complemento também com os nossos escondidos (o padrão): pessoas + robôs = todos, sem sobra nem repetição.
        $total = fn (array $query) => $this->get(route('admin.accesses', $query))->assertOk()->viewData('recent')->total();
        $this->assertSame([10, 2, 8], [$total([]), $total(['robos' => 'sem']), $total(['robos' => 'so'])]);
        $byRobot = fn (array $query) => $this->get(route('admin.accesses.robots', $query))->assertOk()->viewData('byRobot')->pluck('owner')->all();
        // A aba Robôs (só robôs declarados, que nunca são pessoa) não tem o "Quem": "Só pessoas" não a zera.
        $this->assertSame([['Google'], ['Google'], ['Google']], [$byRobot([]), $byRobot(['robos' => 'so']), $byRobot(['robos' => 'sem'])]);

        // Origens: só a pessoa (as duas páginas dela, e só o Referer de fora).
        $origins = $this->get(route('admin.accesses.origins'))->assertOk();
        $this->assertEqualsCanonicalizing(['bsky.app', 'outro.example'], $origins->viewData('referrers')->pluck('domain')->all());
    }

    public function test_people_and_robots_cards_add_up_to_the_pages_opened(): void
    {
        $this->visit('203.0.113.7', 5000, ['path' => '/']);
        $this->visit('203.0.113.7', 5000, ['path' => '/sobre']);
        // O redirecionamento (3xx) não é página aberta: não entra em nenhum dos três cartões.
        $this->visit('203.0.113.7', null, ['path' => '/idioma/en', 'status' => 302]);
        $this->visit('198.51.100.9', null, ['path' => '/', 'is_bot' => true]);
        $this->visit('198.51.100.9', null, ['path' => '/idioma/en', 'status' => 302, 'is_bot' => true]);
        $this->visit('198.51.100.10', null, ['path' => '/sobre']);
        $this->visit('127.0.0.1', null, ['path' => '/']);

        $this->actingAs($this->admin());
        foreach ([[], ['nossos' => 'mostrar']] as $query) {
            $totals = $this->get(route('admin.accesses', $query))->assertOk()->viewData('totals');
            $this->assertSame((int) $totals->views, (int) $totals->people_views + (int) $totals->bots, json_encode($query));
        }

        $page = $this->get(route('admin.accesses'))->assertOk();
        $totals = $page->viewData('totals');
        $this->assertSame([4, 2, 1, 2, 2], [(int) $totals->views, (int) $totals->people_views, (int) $totals->people, (int) $totals->bots, (int) $totals->bot_ips]);
        $page->assertSee('1 IP(s) · ')->assertSee('2 IP(s) · declarados: 1');
    }
}
