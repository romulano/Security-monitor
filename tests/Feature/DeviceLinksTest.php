<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\DeviceSighting;
use App\Models\PageView;
use App\Models\User;
use App\Services\Admin\DeviceLinks;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Admin → Acessos: o mesmo aparelho em outros IPs, na ficha do IP e na aba Ataques, sem nunca chegar à conta. */
class DeviceLinksTest extends TestCase
{
    use RefreshDatabase;

    private const FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:144.0) Gecko/20100101 Firefox/144.0';

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
    }

    private function seen(string $code, string $ip, string $userAgent = self::FIREFOX): void
    {
        DeviceSighting::query()->create([
            'fingerprint' => str_pad($code, 64, '0'), 'ip' => $ip, 'day' => now()->toDateString(), 'first_seen' => now()->subDays(2),
            'last_seen' => now()->subHour(), 'hits' => 1, 'country' => 'NL', 'user_agent' => $userAgent,
        ]);
    }

    private function attack(string $ip): void
    {
        AccessEvent::query()->create(['created_at' => now()->subHour(), 'ip' => $ip, 'method' => 'GET', 'path' => "/?id=1' or 1=1--", 'status' => 200, 'category' => 'sqli']);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_the_ip_file_lists_the_other_ips_of_the_same_device_and_never_the_account(): void
    {
        foreach (['203.0.113.7', '198.51.100.1', '198.51.100.2'] as $ip) {
            $this->seen('aaaa', $ip);
        }
        $this->seen('bbbb', '203.0.113.7');
        $this->seen('bbbb', '198.51.100.3', 'Mozilla/5.0 Chrome/145.0');
        foreach (range(1, 20) as $i) {
            $this->seen('cccc', "192.0.2.{$i}");
        }
        $this->seen('cccc', '203.0.113.7');
        // O IP já teve uma conta logada em page_views: o bloco do aparelho não chega a ela.
        $user = User::factory()->create(['email' => 'dona-da-conta@exemplo.com']);
        PageView::query()->create(['token' => (string) Str::uuid(), 'ip' => '203.0.113.7', 'user_id' => $user->id, 'path' => '/', 'is_bot' => false, 'status' => 200, 'created_at' => now()->subHour()]);

        $page = $this->actingAs($this->admin())->get(route('admin.accesses.ip', ['ip' => '203.0.113.7']))->assertOk()
            ->assertSee('Mesmo aparelho em outros IPs')
            ->assertSee('aparelho comum (21 IPs)')
            ->assertDontSee('dona-da-conta@exemplo.com')
            ->assertDontSee('192.0.2.1"', false);

        $codes = $page->viewData('devices')->keyBy(fn ($code) => substr($code->fingerprint, 0, 4));
        $this->assertEqualsCanonicalizing([['198.51.100.1', 'alto'], ['198.51.100.2', 'alto']], $codes['aaaa']->others->map(fn ($o) => [$o->ip, $o->confidence])->all());
        $this->assertSame([['198.51.100.3', 'provável']], $codes['bbbb']->others->map(fn ($o) => [$o->ip, $o->confidence])->all());
        $this->assertSame(['common', 0], [$codes['cccc']->level, $codes['cccc']->others->count()]);
    }

    public function test_the_attacks_tab_shows_devices_that_moved_between_attacking_ips_without_ours(): void
    {
        foreach (['203.0.113.7', '198.51.100.1', '127.0.0.1'] as $ip) {
            $this->attack($ip);
            $this->seen('aaaa', $ip);
        }

        $page = $this->actingAs($this->admin())->get(route('admin.accesses.attacks', ['nossos' => 'mostrar']))->assertOk()->assertSee('Aparelhos que trocaram de IP');

        $moved = $page->viewData('moved');
        $this->assertCount(1, $moved);
        $this->assertEqualsCanonicalizing(['203.0.113.7', '198.51.100.1'], $moved->first()->among);
        $this->assertSame([2, 'alto'], [$page->viewData('deviceIps')['203.0.113.7']->others, $page->viewData('deviceIps')['203.0.113.7']->confidence]);
        $this->assertArrayNotHasKey('127.0.0.1', $page->viewData('deviceIps'));
    }

    public function test_only_device_sightings_is_read_and_the_tabs_stay_under_the_ceiling(): void
    {
        $this->seen('aaaa', '203.0.113.7');
        $this->seen('aaaa', '198.51.100.1');
        $this->attack('203.0.113.7');

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(DeviceLinks::class)->forIp('203.0.113.7');
        app(DeviceLinks::class)->forAttackers(['203.0.113.7']);
        $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();
        $this->assertStringNotContainsString('users', $sql);
        $this->assertStringNotContainsString('page_views', $sql);

        $this->actingAs($this->admin());
        $this->get(route('admin.accesses'))->assertOk();
        $queries = function (string $route, array $query): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route($route, $query))->assertOk();
            DB::disableQueryLog();

            return collect(DB::getQueryLog())->reject(fn (array $q) => str_starts_with($q['query'], 'set local'))->count();
        };

        // Mais 1 na ficha: quantos registros o IP tem guardados, para o diálogo de "Apagar tudo deste IP".
        $this->assertLessThanOrEqual(4 + 2 + 1, $queries('admin.accesses.ip', ['ip' => '203.0.113.7']));
        $this->assertLessThanOrEqual(7 + 1, $queries('admin.accesses.attacks', []));
    }
}
