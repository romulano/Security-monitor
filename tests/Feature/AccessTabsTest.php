<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\PageView;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin → Acessos em abas: cada uma com as suas consultas, as barras no
 * horário de Brasília, as origens só de pessoas e quem conta como pessoa.
 */
class AccessTabsTest extends TestCase
{
    use RefreshDatabase;

    private const TABS = ['admin.accesses' => 7, 'admin.accesses.robots' => 4, 'admin.accesses.origins' => 3];

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        PageView::query()->delete();
        $this->travelTo(Carbon::parse('2026-11-04 12:00:00', 'UTC'));
    }

    private function visit(string $ip, string $path, array $columns = []): PageView
    {
        return PageView::query()->create($columns + [
            'token' => (string) Str::uuid(), 'ip' => $ip, 'user_agent' => 'Mozilla/5.0 Firefox/144.0', 'path' => $path,
            'is_bot' => false, 'status' => 200, 'duration_ms' => 30_000, 'created_at' => now()->subHour(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_each_tab_opens_only_for_the_admin_with_few_queries(): void
    {
        $this->visit('203.0.113.7', '/sobre?utm_source=bluesky', ['referer' => 'https://bsky.app/profile/x']);
        $this->visit('198.51.100.9', '/', ['is_bot' => true, 'bot_kind' => 'search', 'bot_owner' => 'Google', 'duration_ms' => null]);

        foreach (array_keys(self::TABS) as $tab) {
            $this->actingAs(User::factory()->create())->get(route($tab))->assertForbidden();
            auth()->logout();
        }

        $this->actingAs($this->admin());

        foreach (self::TABS as $tab => $ceiling) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route($tab, ['periodo' => '7d']))->assertOk()->assertSee('Visão geral')->assertSee('Origens');
            $queries = collect(DB::getQueryLog())->reject(fn (array $q) => str_starts_with($q['query'], 'set local'))->count();
            DB::disableQueryLog();
            $this->assertLessThanOrEqual($ceiling, $queries, $tab);
        }
    }

    public function test_the_bars_fall_on_the_hour_of_sao_paulo(): void
    {
        // 02:30 UTC é 23:30 do dia anterior em Brasília.
        $this->visit('203.0.113.7', '/sobre', ['created_at' => Carbon::parse('2026-11-04 02:30:00', 'UTC')]);
        $this->visit('198.51.100.9', '/', ['is_bot' => true, 'created_at' => Carbon::parse('2026-11-04 02:40:00', 'UTC')]);

        $series = $this->actingAs($this->admin())->get(route('admin.accesses'))->assertOk()->viewData('series');

        $this->assertSame(25, $series->count());
        $this->assertSame([['23h', 1, 1]], $series->filter(fn ($bar) => $bar->people + $bar->robots > 0)->map(fn ($bar) => [$bar->label, $bar->people, $bar->robots])->values()->all());
        $this->assertSame('9h', $series->last()->label);
    }

    public function test_origins_count_only_people(): void
    {
        $this->visit('203.0.113.7', '/sobre?utm_source=bluesky&utm_campaign=lancamento', ['referer' => 'https://www.bsky.app/profile/x']);
        $this->visit('203.0.113.7', '/cadastro', ['referer' => 'http://localhost/sobre', 'created_at' => now()->subMinutes(30)]);
        // Robô declarado, provável robô (404 em série), atacante e nosso: nenhum entra.
        $this->visit('198.51.100.9', '/?utm_source=robo', ['is_bot' => true, 'referer' => 'https://robo.example/']);
        foreach (['/contact', '/imprint', '/about-us', '/legal', '/team'] as $path) {
            $this->visit('198.51.100.20', $path, ['status' => 404, 'referer' => 'https://scan.example/']);
        }
        $this->visit('198.51.100.30', '/?utm_source=atacante', ['referer' => 'https://evil.example/']);
        AccessEvent::query()->create(['created_at' => now()->subHour(), 'ip' => '198.51.100.30', 'method' => 'GET', 'path' => "/?id=1' or 1=1--", 'status' => 200, 'category' => 'sqli']);
        $this->visit('127.0.0.1', '/?utm_source=deploy', ['referer' => 'https://nosso.example/']);

        $page = $this->actingAs($this->admin())->get(route('admin.accesses.origins'))->assertOk();

        $this->assertSame([['bsky.app', 1]], $page->viewData('referrers')->map(fn ($r) => [$r->domain, (int) $r->views])->all());
        // Sem campanha (utm) nem link para Admin → Campanhas, que saiu.
        $page->assertDontSee('Admin → Campanhas');
        $this->assertArrayNotHasKey('campaigns', $page->original->getData());
        $this->get('/admin/campanhas')->assertNotFound();
        $this->assertSame([['/sobre', 1]], $page->viewData('entries')->map(fn ($r) => [$r->page, (int) $r->visits])->all());
        $this->assertSame(['http://localhost/sobre'], $page->viewData('toSignup')->pluck('page')->all());
    }

    public function test_a_fast_script_a_404_sweep_and_redirects_do_not_count_as_people(): void
    {
        // O roteiro do Tor: 12 páginas em 22 s, sem o tempo da página.
        foreach (range(0, 11) as $i) {
            $this->visit('185.220.101.1', '/materia/'.Str::uuid(), ['country' => 'T1', 'duration_ms' => null, 'created_at' => now()->subHour()->addSeconds($i * 2)]);
        }
        // Gente: com o tempo, e a troca de idioma (302) não conta como página.
        $this->visit('203.0.113.7', '/sobre');
        $this->visit('203.0.113.7', '/idioma/en', ['status' => 302, 'duration_ms' => null]);
        // 3 páginas espaçadas, sem o tempo (sem JavaScript): nunca ficou 1 s numa página, então é provável robô.
        // Antes da regra do 1 s, era gente; o navegador de hoje roda o JavaScript e manda o tempo.
        foreach (range(0, 2) as $i) {
            $this->visit('203.0.113.8', '/sobre', ['duration_ms' => null, 'created_at' => now()->subHours(3 - $i)]);
        }

        $page = $this->actingAs($this->admin())->get(route('admin.accesses'))->assertOk()->assertSee('provável robô');
        $byIp = $page->viewData('byIp')->keyBy('ip');
        $totals = $page->viewData('totals');

        $this->assertTrue($byIp['185.220.101.1']->likely_bot);
        $this->assertFalse($byIp['203.0.113.7']->likely_bot);
        $this->assertTrue($byIp['203.0.113.8']->likely_bot);
        // Robô é o que não é pessoa: as 12 do roteiro e as 3 sem o tempo (o 302 do 203.0.113.7 é de pessoa).
        $this->assertSame([16, 1, 1, 15], [(int) $totals->views, (int) $totals->redirects, (int) $totals->people, (int) $totals->bots]);
        $this->assertNotContains('/idioma/en', $page->viewData('byPage')->pluck('page')->all());

        $people = $this->get(route('admin.accesses', ['robos' => 'sem']))->assertOk();
        $this->assertSame(['203.0.113.7'], $people->viewData('byIp')->pluck('ip')->all());
    }
}
