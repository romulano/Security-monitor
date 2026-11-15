<?php

namespace Tests\Feature;

use App\Models\PageView;
use App\Models\User;
use App\Services\Maintenance\RetentionPruner;
use App\Services\Monitoring\PageViewRecorder;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Admin → Acessos: cada página aberta no site vira um registro com IP, país,
 * navegador, conta, página, resposta e o tempo que o navegador manda ao sair.
 */
class PageViewsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        // Teste sem RefreshDatabase que abre uma página grava fora da transação e o registro fica.
        PageView::query()->delete();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function open(string $path, array $headers = []): TestResponse
    {
        return $this->withHeaders($headers + ['User-Agent' => self::CHROME, 'CF-IPCountry' => 'br'])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->get($path);
    }

    public function test_an_open_page_records_who_from_where_and_what(): void
    {
        $response = $this->open('/sobre?de=teste', ['Referer' => 'https://www.google.com/']);

        $view = PageView::sole();
        $this->assertSame('203.0.113.7', $view->ip);
        $this->assertSame('BR', $view->country);
        $this->assertSame(self::CHROME, $view->user_agent);
        $this->assertFalse($view->is_bot);
        $this->assertSame('/sobre?de=teste', $view->path);
        $this->assertSame('https://www.google.com/', $view->referer);
        $this->assertSame(200, $view->status);
        $this->assertNull($view->user_id);
        $this->assertNull($view->duration_ms);
        // A página leva o token para mandar o tempo depois.
        $response->assertSee('<meta name="page-view" content="'.$view->token.'"', false);
    }

    public function test_the_logged_in_account_a_declared_bot_and_a_404_are_recorded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/sobre')->assertOk();
        $this->open('/nao-existe-'.uniqid(), ['User-Agent' => 'Googlebot/2.1'])->assertNotFound();

        $this->assertSame($user->id, PageView::query()->where('path', '/sobre')->sole()->user_id);
        $bot = PageView::query()->where('status', 404)->sole();
        $this->assertTrue($bot->is_bot);
    }

    public function test_the_admin_json_and_a_switched_off_monitor_are_left_out(): void
    {
        $this->actingAs($this->admin())->get('/sobre')->assertOk();
        $this->get(route('admin.accesses'))->assertOk();
        auth()->logout();
        $this->open('/api/news', ['Accept' => 'application/json']);

        $this->assertSame(0, PageView::query()->count());

        Settings::set(PageViewRecorder::SETTING, false);
        $this->open('/sobre')->assertOk()->assertDontSee('name="page-view"', false);

        $this->assertSame(0, PageView::query()->count());
    }

    public function test_the_time_on_the_page_only_grows_and_has_a_ceiling(): void
    {
        $this->open('/sobre');
        $view = PageView::sole();
        $send = fn (int $ms) => $this->post(route('page-views.duration'), ['token' => $view->token, 'ms' => $ms]);

        $send(42_000)->assertNoContent();
        $this->assertSame(42_000, $view->fresh()->duration_ms);

        // A aba voltou e sumiu de novo antes: o total menor não apaga o maior.
        $send(10_000)->assertNoContent();
        $this->assertSame(42_000, $view->fresh()->duration_ms);

        $send(PageViewRecorder::MAX_DURATION_MS + 1000)->assertNoContent();
        $this->assertSame(PageViewRecorder::MAX_DURATION_MS, $view->fresh()->duration_ms);

        $this->post(route('page-views.duration'), ['token' => 'nao-e-uuid', 'ms' => 1])->assertSessionHasErrors('token');
    }

    public function test_the_time_of_an_old_page_is_not_accepted(): void
    {
        $this->open('/sobre');
        $view = PageView::sole();
        $this->travel(5)->hours();

        $this->post(route('page-views.duration'), ['token' => $view->token, 'ms' => 1000])->assertNoContent();

        $this->assertNull($view->fresh()->duration_ms);
    }

    public function test_the_admin_sees_who_accessed_how_often_and_can_filter_and_switch_off(): void
    {
        $this->open('/sobre');
        $this->open('/sobre');
        $this->open('/termos', ['CF-IPCountry' => 'US']);
        PageView::query()->create([
            'token' => (string) Str::uuid(), 'ip' => '198.51.100.9', 'country' => 'DE', 'user_agent' => 'curl/8.0',
            'is_bot' => true, 'path' => '/', 'status' => 200, 'created_at' => now(),
        ]);

        $this->actingAs($this->admin());

        $this->get(route('admin.accesses'))
            ->assertOk()
            ->assertSee('203.0.113.7')
            ->assertSee('198.51.100.9')
            ->assertSee('/termos')
            ->assertSee(self::CHROME)
            ->assertViewHas('byIp', fn ($rows) => $rows->first()->ip === '203.0.113.7' && (int) $rows->first()->views === 3);

        $this->get(route('admin.accesses', ['robos' => 'sem']))->assertOk()->assertDontSee('198.51.100.9');
        $this->get(route('admin.accesses', ['ip' => '198.51.100.9']))->assertOk()->assertDontSee('203.0.113.7');
        $this->get(route('admin.accesses', ['periodo' => '1ano']))->assertSessionHasErrors('periodo');

        $this->post(route('admin.accesses.toggle'))->assertRedirect();
        $this->assertFalse(Settings::bool(PageViewRecorder::SETTING));
    }

    public function test_only_the_admin_opens_the_accesses_page(): void
    {
        $this->get(route('admin.accesses'))->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create())->get(route('admin.accesses'))->assertForbidden();
    }

    public function test_records_go_away_after_six_months(): void
    {
        $this->open('/sobre');
        $this->travel(181)->days();
        $this->open('/termos');

        app(RetentionPruner::class)->run();

        $this->assertSame(['/termos'], PageView::query()->pluck('path')->all());
    }
}
