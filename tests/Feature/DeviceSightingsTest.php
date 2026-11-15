<?php

namespace Tests\Feature;

use App\Models\DeviceSighting;
use App\Models\PageView;
use App\Models\User;
use App\Services\Maintenance\RetentionPruner;
use App\Services\Monitoring\DeviceSightings;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O código do aparelho de quem navega sem login, só para segurança: vem no
 * beacon do tempo da página, fica só o HMAC, sem vínculo com conta, com teto
 * por IP, poda de 6 meses e saída junto com o "apagar por IP".
 */
class DeviceSightingsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        PageView::query()->delete();
    }

    private function open(string $path = '/sobre', array $headers = [], string $ip = '203.0.113.7'): TestResponse
    {
        return $this->withHeaders($headers + ['User-Agent' => self::CHROME, 'CF-IPCountry' => 'br'])
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get($path);
    }

    /** Abre uma página e manda o beacon com o código; devolve a resposta do beacon. */
    private function beacon(string $hash, array $headers = [], string $ip = '203.0.113.7', ?User $user = null): TestResponse
    {
        $user ? $this->actingAs($user)->get('/sobre') : $this->open('/sobre', [], $ip);
        $token = PageView::query()->latest('id')->value('token');

        $response = $this->withHeaders($headers)->post(route('page-views.duration'), ['token' => $token, 'ms' => 1000, 'aparelho' => $hash]);
        $this->flushHeaders();

        return $response;
    }

    private function code(string $char = 'a'): string
    {
        return str_repeat($char, 64);
    }

    public function test_only_a_visitor_without_login_and_without_gpc_is_asked_for_the_code(): void
    {
        $this->open()->assertOk()->assertSee('data-device="1"', false);
        $this->open('/sobre', ['Sec-GPC' => '1'])->assertOk()->assertSee('name="page-view"', false)->assertDontSee('data-device', false);
        $this->actingAs(User::factory()->create())->get('/sobre')->assertOk()->assertSee('name="page-view"', false)->assertDontSee('data-device', false);
    }

    public function test_the_beacon_keeps_only_the_hmac_with_the_ip_country_and_browser_of_the_page(): void
    {
        $this->beacon($hash = $this->code())->assertNoContent();

        $sighting = DeviceSighting::sole();
        $this->assertSame(hash_hmac('sha256', $hash, 'secmon:aparelho:'.config('app.key')), $sighting->fingerprint);
        $this->assertNotSame($hash, $sighting->fingerprint);
        $this->assertSame(['203.0.113.7', 'BR', self::CHROME, 1], [$sighting->ip, $sighting->country, $sighting->user_agent, $sighting->hits]);
        $this->assertSame(now()->utc()->toDateString(), $sighting->day->toDateString());
        // O tempo da página continua chegando junto.
        $this->assertSame(1000, PageView::sole()->duration_ms);
    }

    public function test_anything_but_a_lowercase_sha256_is_refused(): void
    {
        $this->open();
        $token = PageView::sole()->token;

        foreach ([str_repeat('a', 63), str_repeat('A', 64), str_repeat('g', 64), 'webgl=NVIDIA;tela=1920x1080'] as $bad) {
            $this->postJson(route('page-views.duration'), ['token' => $token, 'ms' => 1, 'aparelho' => $bad])->assertStatus(422);
        }

        $this->assertSame(0, DeviceSighting::query()->count());
    }

    public function test_a_page_of_an_account_an_old_page_an_unknown_token_or_gpc_records_nothing(): void
    {
        $this->beacon($this->code('b'), ['Sec-GPC' => '1'])->assertNoContent();
        $this->beacon($this->code(), user: User::factory()->create())->assertNoContent();
        auth()->logout();

        $this->open();
        $old = PageView::query()->latest('id')->value('token');
        $this->travel(5)->hours();
        $this->post(route('page-views.duration'), ['token' => $old, 'ms' => 1, 'aparelho' => $this->code('c')])->assertNoContent();
        $this->post(route('page-views.duration'), ['token' => '9b2f0d4e-1c3a-4f5b-8d6e-7a8b9c0d1e2f', 'ms' => 1, 'aparelho' => $this->code('d')])->assertNoContent();

        $this->assertSame(0, DeviceSighting::query()->count());
    }

    public function test_the_same_code_on_the_same_ip_and_day_is_one_row(): void
    {
        $this->beacon($this->code());
        $this->travel(10)->minutes();
        $this->beacon($this->code());

        $sighting = DeviceSighting::sole();
        $this->assertSame(2, $sighting->hits);
        $this->assertTrue($sighting->last_seen->greaterThan($sighting->first_seen));
    }

    public function test_an_ip_records_at_most_three_codes_a_day(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $char) {
            $this->beacon($this->code($char))->assertNoContent();
        }

        $this->assertSame(3, DeviceSighting::query()->count());
        $this->assertSame(0, DeviceSighting::query()->where('fingerprint', hash_hmac('sha256', $this->code('d'), 'secmon:aparelho:'.config('app.key')))->count());

        // Um código que o IP já tem continua contando; outro IP tem o seu próprio teto.
        $this->beacon($this->code('a'));
        $this->beacon($this->code('d'), ip: '198.51.100.9');
        $this->assertSame(4, DeviceSighting::query()->count());
        $this->assertSame(2, DeviceSighting::query()->where('ip', '203.0.113.7')->max('hits'));
    }

    public function test_the_table_has_no_link_to_an_account(): void
    {
        $columns = Schema::getColumnListing('device_sightings');

        foreach (['user_id', 'token', 'page_view_id'] as $column) {
            $this->assertNotContains($column, $columns);
        }
    }

    public function test_reading_touches_only_the_device_table_and_tells_how_rare_a_code_is(): void
    {
        foreach (range(1, 6) as $n) {
            DeviceSighting::query()->create(['fingerprint' => $this->code(), 'ip' => "198.51.100.{$n}", 'day' => now()->toDateString(), 'first_seen' => now(), 'last_seen' => now()->subMinutes($n)]);
        }
        // Fora da janela de 30 dias: não conta.
        DeviceSighting::query()->create(['fingerprint' => $this->code(), 'ip' => '198.51.100.99', 'day' => now()->subDays(40)->toDateString(), 'first_seen' => now()->subDays(40), 'last_seen' => now()->subDays(40)]);
        $devices = app(DeviceSightings::class);

        DB::enableQueryLog();
        $ips = $devices->ipsOf($this->code());
        $rarity = $devices->rarity($this->code());
        $queries = collect(DB::getQueryLog())->pluck('query');

        $this->assertSame(['198.51.100.1', '198.51.100.2'], $ips->take(2)->pluck('ip')->all());
        $this->assertCount(6, $ips);
        $this->assertSame(['ips' => 6, 'level' => 'medium'], $rarity);
        $this->assertTrue($queries->every(fn (string $sql) => str_contains($sql, 'device_sightings') && ! preg_match('/users|page_views|join/i', $sql)));
        $this->assertSame('rare', $devices->rarity($this->code('z'))['level']);
    }

    public function test_codes_go_away_six_months_after_they_were_last_seen(): void
    {
        $old = DeviceSighting::query()->create(['fingerprint' => $this->code(), 'ip' => '203.0.113.7', 'day' => now()->subDays(200)->toDateString(), 'first_seen' => now()->subDays(200), 'last_seen' => now()->subDays(181)]);
        $kept = DeviceSighting::query()->create(['fingerprint' => $this->code('b'), 'ip' => '203.0.113.7', 'day' => now()->subDays(200)->toDateString(), 'first_seen' => now()->subDays(200), 'last_seen' => now()->subDays(179)]);

        app(RetentionPruner::class)->run();

        $this->assertSame([$kept->id], DeviceSighting::query()->pluck('id')->all());
        $this->assertNull(DeviceSighting::query()->find($old->id));
    }

    public function test_the_privacy_page_tells_about_the_code(): void
    {
        $text = preg_replace('/\s+/u', ' ', strip_tags($this->get('/privacidade')->assertOk()->getContent()));

        foreach ([
            'Não vendemos dados, não exibimos anúncios e não usamos cookies de rastreamento; o código de aparelho da seção 2 serve só à segurança.',
            'Código do aparelho (só para segurança): quando você navega sem estar logado, o seu navegador calcula, a partir de características técnicas do aparelho (placa de vídeo, tela, fuso horário, idiomas, número de núcleos, memória e fontes instaladas), um código que não revela essas características.',
            'Se o seu navegador enviar o sinal Global Privacy Control, ou estiver com o JavaScript desligado, o código não é calculado.',
            'e registros de acesso, inclusive reconhecer o mesmo aparelho quando ele troca de IP.',
            'Também não usamos técnicas parecidas para medir audiência: o código do aparelho (seção 2) é só para segurança.',
            'Código do aparelho (com o IP, o país, o navegador e o dia): 6 meses.',
        ] as $sentence) {
            $this->assertStringContainsString($sentence, $text);
        }
    }
}
