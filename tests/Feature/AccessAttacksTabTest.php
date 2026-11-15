<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\PageView;
use App\Models\User;
use App\Services\Auth\RejectedAttempts;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Admin → Acessos: a aba Ataques (o mapa de recon e ataque) e a ficha de um IP. */
class AccessAttacksTabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        AccessEvent::query()->delete();
        PageView::query()->delete();
    }

    private function event(string $ip, string $path, array $columns = []): AccessEvent
    {
        return AccessEvent::query()->create($columns + [
            'created_at' => now()->subHour(), 'ip' => $ip, 'method' => 'GET', 'path' => $path, 'status' => 404, 'category' => null,
            'user_agent' => 'Mozilla/5.0 Firefox/144.0',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** @return int as consultas da requisição, sem o `set local` do teto de tempo */
    private function queries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        DB::disableQueryLog();

        return collect(DB::getQueryLog())->reject(fn (array $q) => str_starts_with($q['query'], 'set local'))->count();
    }

    public function test_categories_sweeps_and_ours_never_an_attacker(): void
    {
        foreach (range(1, 10) as $i) {
            $this->event('198.51.100.9', "/pagina-{$i}");
        }
        $this->event('198.51.100.9', '/wp-login.php', ['category' => 'cms']);
        $this->event('203.0.113.7', "/?id=1' or 1=1--", ['category' => 'sqli', 'status' => 200]);
        $this->event('127.0.0.1', '/?id=1+union+select+1', ['category' => 'sqli', 'status' => 200]);

        $this->actingAs($this->admin());
        $page = $this->get(route('admin.accesses.attacks'))->assertOk()
            ->assertSee('O que a Cloudflare, a borda e o nginx barram antes do PHP');

        $this->assertEqualsCanonicalizing([[null, 10, 1], ['cms', 1, 1], ['sqli', 1, 1]], $page->viewData('byCategory')->map(fn ($r) => [$r->category, (int) $r->events, (int) $r->ips])->all());
        // Quem varre ganha o selo "varredura" em IPs com mais eventos (o quadro próprio saiu).
        $this->assertSame(11, (int) $page->viewData('byIp')->firstWhere('ip', '198.51.100.9')->paths);
        $page->assertSee('11 endereços com 4xx">varredura</span>', false);
        $byIp = $page->viewData('byIp')->keyBy('ip');
        $this->assertTrue($byIp['203.0.113.7']->attacker);
        $this->assertFalse($byIp['198.51.100.9']->attacker, 'varrer não é mandar carga de ataque');
        $this->assertArrayNotHasKey('127.0.0.1', $byIp->all(), 'os nossos ficam escondidos');

        $shown = $this->get(route('admin.accesses.attacks', ['nossos' => 'mostrar', 'categoria' => 'sqli']))->assertOk()->viewData('byIp')->keyBy('ip');
        $this->assertTrue($shown['127.0.0.1']->ours);
        $this->assertFalse($shown['127.0.0.1']->attacker);
    }

    public function test_the_payload_is_escaped_and_accounts_come_from_the_hmac(): void
    {
        $this->event('203.0.113.7', '/?x=<script>alert(1)</script>', ['category' => 'xss_ssti', 'status' => 200]);
        foreach (['ana@x.com', 'bia@x.com', 'caio@x.com'] as $email) {
            $this->event('203.0.113.7', '/login', ['method' => 'POST', 'status' => 302, 'category' => 'autenticacao', 'auth' => 'login_senha', 'account_hmac' => RejectedAttempts::hmac($email)]);
        }
        $this->event('198.51.100.9', '/login', ['method' => 'POST', 'status' => 302, 'category' => 'autenticacao', 'auth' => 'login_senha', 'account_hmac' => RejectedAttempts::hmac('ana@x.com')]);

        $page = $this->actingAs($this->admin())->get(route('admin.accesses.attacks'))->assertOk()
            ->assertSee('/?x=&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)', false);

        $this->assertSame([3, 3], [(int) $page->viewData('byIp')->firstWhere('ip', '203.0.113.7')->refusals, (int) $page->viewData('byIp')->firstWhere('ip', '203.0.113.7')->accounts]);
        $this->assertSame([[RejectedAttempts::hmac('ana@x.com'), 2]], $page->viewData('accounts')->map(fn ($r) => [$r->account_hmac, (int) $r->ips])->all());
    }

    public function test_the_ip_file_joins_both_tables_once(): void
    {
        $view = PageView::query()->create([
            'token' => (string) Str::uuid(), 'ip' => '203.0.113.7', 'country' => 'BR', 'user_agent' => 'Mozilla/5.0 Firefox/144.0',
            'path' => '/wp-admin/', 'is_bot' => false, 'status' => 404, 'created_at' => now()->subDays(100),
        ]);
        $this->event('203.0.113.7', '/wp-admin/', ['category' => 'cms', 'page_view_id' => $view->id, 'created_at' => now()->subDays(100)]);
        $this->event('203.0.113.7', '/nao-existe', ['method' => 'POST']);
        $this->event('198.51.100.9', '/outro');

        $this->actingAs($this->admin());
        $page = $this->get(route('admin.accesses.ip', ['ip' => '203.0.113.7']))->assertOk()->assertSee('Tudo o que este IP fez');

        $this->assertSame([['evento', 'POST /nao-existe', null], ['acesso', 'GET /wp-admin/', 'cms']], $page->viewData('timeline')->getCollection()->map(fn ($r) => [$r->source, $r->method.' '.$r->path, $r->category])->all());
        $this->assertSame(1, $this->get(route('admin.accesses.ip', ['ip' => '203.0.113.7', 'periodo' => '30d']))->viewData('timeline')->total());

        $this->get(route('admin.accesses.ip', ['ip' => 'nao-e-ip']))->assertSessionHasErrors('ip');
        $this->actingAs(User::factory()->create())->get(route('admin.accesses.ip', ['ip' => '203.0.113.7']))->assertForbidden();
        $this->get(route('admin.accesses.attacks'))->assertForbidden();
    }

    public function test_each_tab_stays_under_its_query_ceiling(): void
    {
        $this->event('203.0.113.7', "/?id=1' or 1=1--", ['category' => 'sqli']);
        $this->actingAs($this->admin());
        // A chave da monitoria (Settings) vem do cache depois da 1ª página: conta só o que é da aba.
        $this->get(route('admin.accesses'))->assertOk();

        // Mais as do mesmo aparelho em outros IPs (DeviceLinks): 1 em Ataques e 2 na ficha; e, na ficha, a contagem
        // da zona de perigo (AccessEraser::countByIp, só deste IP).
        $this->assertLessThanOrEqual(7 + 1, $this->queries(fn () => $this->get(route('admin.accesses.attacks', ['periodo' => '7d']))->assertOk()));
        $this->assertLessThanOrEqual(4 + 2 + 1, $this->queries(fn () => $this->get(route('admin.accesses.ip', ['ip' => '203.0.113.7']))->assertOk()));
    }

    public function test_the_bar_filters_refused_only_sweeps_and_hides_empty_boxes(): void
    {
        $this->event('203.0.113.7', "/?id=1' or 1=1--", ['category' => 'sqli', 'status' => 200]);
        $this->event('203.0.113.8', '/nao-existe');
        // 60 IPs com mais eventos que o varredor: ele fica fora dos 50, e "Só varredura" o traz.
        foreach (range(1, 60) as $i) {
            foreach (range(1, 12) as $n) {
                $this->event('198.51.100.'.$i, '/mesmo', ['category' => 'cms']);
            }
        }
        foreach (range(1, 10) as $i) {
            $this->event('192.0.2.9', "/sonda-{$i}");
        }
        $this->actingAs($this->admin());

        $page = $this->get(route('admin.accesses.attacks'))->assertOk()
            ->assertSee('Mais filtros')
            ->assertSee('Só recusado (sem categoria)')
            ->assertDontSee('Mesma conta tentada de 2 IPs ou mais')
            ->assertDontSee('Aparelhos que trocaram de IP')
            ->assertSee('Ver os 50 IPs ›')
            // A linha da categoria filtra a própria aba.
            ->assertSee('href="'.e(route('admin.accesses.attacks', ['categoria' => 'sqli'])).'"', false)
            ->assertSee('href="'.e(route('admin.accesses.attacks', ['categoria' => 'recusado'])).'"', false);
        $this->assertNotContains('192.0.2.9', $page->viewData('byIp')->pluck('ip')->all());
        $this->assertSame(25, $page->viewData('events')->perPage());

        $refused = $this->get(route('admin.accesses.attacks', ['categoria' => 'recusado']))->assertOk()->assertSee('Categoria só recusado');
        $this->assertEqualsCanonicalizing(['203.0.113.8', '192.0.2.9'], $refused->viewData('byIp')->pluck('ip')->all());

        $sweeps = $this->get(route('admin.accesses.attacks', ['varredura' => '1']))->assertOk()->assertSee('Só varredura');
        $this->assertSame(['192.0.2.9'], $sweeps->viewData('byIp')->pluck('ip')->all());

        $this->get(route('admin.accesses.attacks', ['varredura' => '2']))->assertSessionHasErrors('varredura');
        $this->get(route('admin.accesses.attacks', ['mais' => 'paginas']))->assertSessionHasErrors('mais');
    }
}
