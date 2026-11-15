<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\PageView;
use App\Models\User;
use App\Services\Admin\AccessEraser;
use App\Services\Maintenance\RetentionPruner;
use App\Services\Monitoring\AccessEventRecorder;
use App\Services\Monitoring\PageViewRecorder;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Admin → Acessos: o que o site recusa ou que tem cara de ataque vira evento,
 * de qualquer método e com qualquer resposta, com teto por minuto.
 */
class AccessEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        PageView::query()->delete();
        AccessEvent::query()->delete();
    }

    private function send(string $method, string $uri, array $headers = [], string $ip = '203.0.113.7'): TestResponse
    {
        // O call() não leva o withHeaders: os cabeçalhos vão como variáveis do servidor.
        $server = $this->transformHeadersToServerVars($headers + ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/144.0']);

        return $this->call($method, $uri, server: $server + ['REMOTE_ADDR' => $ip]);
    }

    /** @return list<array{string, int, ?string}> */
    private function events(): array
    {
        return AccessEvent::query()->orderBy('id')->get()->map(fn (AccessEvent $e) => [$e->method.' '.$e->path, $e->status, $e->category])->all();
    }

    public function test_what_is_refused_or_odd_is_recorded_with_any_method(): void
    {
        $this->send('POST', '/nao-existe')->assertNotFound();
        $this->send('PROPFIND', '/sobre');
        $this->send('OPTIONS', '/sobre');
        $this->send('HEAD', '/sobre')->assertOk();
        $this->send('GET', '/admin/login')->assertOk();
        $this->send('GET', '/sobre?author=1')->assertOk();
        $this->send('GET', '/sobre')->assertOk();

        $this->assertSame([
            ['POST /nao-existe', 404, null],
            ['PROPFIND /sobre', AccessEvent::query()->where('method', 'PROPFIND')->value('status'), 'metodo'],
            ['OPTIONS /sobre', AccessEvent::query()->where('method', 'OPTIONS')->value('status'), 'metodo'],
            ['GET /admin/login', 200, 'area_admin'],
            ['GET /sobre?author=1', 200, 'cms'],
        ], $this->events());
    }

    public function test_no_header_takes_a_refusal_out(): void
    {
        $this->send('GET', '/nao-existe-1', ['Accept' => 'application/json'])->assertNotFound();
        $this->send('GET', '/nao-existe-2', ['X-Requested-With' => 'XMLHttpRequest'])->assertNotFound();
        $this->send('GET', '/nao-existe-3', ['Sec-Purpose' => 'prefetch'])->assertNotFound();

        $this->assertSame(['/nao-existe-1', '/nao-existe-2', '/nao-existe-3'], AccessEvent::query()->orderBy('id')->pluck('path')->all());
        // Nenhum desses é página: page_views continua de fora.
        $this->assertSame(0, PageView::query()->count());
    }

    public function test_the_404_page_points_to_its_access_and_secrets_stay_out(): void
    {
        $this->send('GET', '/wp-login.php?token=abc123', ['User-Agent' => 'sqlmap/1.8.4#stable (https://sqlmap.org)'])->assertNotFound();

        $event = AccessEvent::sole();
        $this->assertSame(PageView::sole()->id, $event->page_view_id);
        $this->assertSame(['/wp-login.php?token=', 'cms', 'scanner', 'GET'], [$event->path, $event->category, $event->bot_kind, $event->method]);
    }

    public function test_the_admin_the_switched_off_monitor_and_a_good_form_are_left_out(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin/nao-existe')->assertNotFound();
        auth()->logout();
        $this->send('GET', '/up');

        Settings::set(PageViewRecorder::SETTING, false);
        $this->send('POST', '/nao-existe')->assertNotFound();

        $this->assertSame(0, AccessEvent::query()->count());
    }

    public function test_419_and_429_at_the_login_count_as_authentication(): void
    {
        $recorder = app(AccessEventRecorder::class);
        $recorder->record(Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']), new Response('', 419));
        $recorder->record(Request::create('/cadastro', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']), new Response('', 429));
        // Fora do login, o 419 é só recusa.
        $recorder->record(Request::create('/conta', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']), new Response('', 419));

        $this->assertSame([['POST /login', 419, 'autenticacao'], ['POST /cadastro', 429, 'autenticacao'], ['POST /conta', 419, null]], $this->events());
    }

    public function test_one_ip_records_20_a_minute_and_the_rest_is_only_counted(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->send('GET', '/.env')->assertNotFound();
        }

        $this->assertSame(20, AccessEvent::query()->count());
        $this->assertSame(5, (int) DB::table('access_event_overflow')->sum('dropped'));

        // Passado o minuto, o IP volta a ser gravado.
        $this->travel(61)->seconds();
        $this->send('GET', '/.env');
        $this->assertSame(21, AccessEvent::query()->count());
    }

    public function test_many_ips_stop_at_the_ceiling_of_all(): void
    {
        config(['secmon.access_events.per_minute' => 3]);

        foreach (range(1, 5) as $i) {
            $this->send('POST', '/nao-existe', ip: "198.51.100.{$i}");
        }

        $this->assertSame(['198.51.100.1', '198.51.100.2', '198.51.100.3'], AccessEvent::query()->orderBy('id')->pluck('ip')->all());
        $this->assertSame(2, (int) DB::table('access_event_overflow')->value('dropped'));
    }

    public function test_the_terminate_makes_at_most_3_queries(): void
    {
        $recorder = app(AccessEventRecorder::class);
        $request = fn () => Request::create('/wp-login.php', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);
        config(['secmon.access_events.per_ip_per_minute' => 1]);

        foreach (['gravado', 'só contado'] as $case) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $recorder->record($request(), new Response('', 404));
            $this->assertLessThanOrEqual(3, count(DB::getQueryLog()), $case);
        }

        $this->assertSame(1, AccessEvent::query()->count());
    }

    public function test_events_go_away_with_the_ip_and_after_six_months(): void
    {
        $this->send('POST', '/nao-existe');
        $this->send('POST', '/nao-existe', ip: '198.51.100.9');
        DB::table('access_event_overflow')->insert(['minute' => now()->subDays(181)->startOfMinute(), 'dropped' => 7]);

        $this->assertSame(['203.0.113.7' => ['views' => 0, 'events' => 1, 'devices' => 0]], app(AccessEraser::class)->countByIp(['203.0.113.7']));
        $this->assertSame([1, 0], app(AccessEraser::class)->deleteIp('203.0.113.7'));
        $this->assertSame(['198.51.100.9'], AccessEvent::query()->pluck('ip')->all());

        $this->travel(181)->days();
        app(RetentionPruner::class)->run();

        $this->assertSame(0, AccessEvent::query()->count());
        $this->assertSame(0, DB::table('access_event_overflow')->count());
    }
}
