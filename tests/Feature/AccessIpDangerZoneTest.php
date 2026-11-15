<?php

namespace Tests\Feature;

use App\Models\PageView;
use App\Models\User;
use App\Services\Monitoring\OwnAddresses;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Admin → Acessos: a zona de perigo na ficha do IP, a aba Ajustes e a Visão geral sem botão destrutivo. */
class AccessIpDangerZoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        PageView::query()->delete();
    }

    private function visit(string $ip): PageView
    {
        return PageView::query()->create([
            'token' => (string) Str::uuid(), 'ip' => $ip, 'user_agent' => 'Mozilla/5.0 Firefox/144.0', 'path' => '/',
            'is_bot' => false, 'status' => 200, 'duration_ms' => 5000, 'created_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_count_as_ours_puts_the_ip_in_the_list_once_and_stays_on_the_ip_page(): void
    {
        $this->visit('203.0.113.7');
        $this->actingAs($this->admin());

        $this->get(route('admin.accesses.ip', ['ip' => '203.0.113.7']))->assertOk()
            ->assertSee('Zona de perigo')
            ->assertSee('Contar o IP 203.0.113.7 como nosso?', false)
            ->assertSee('Apagar tudo deste IP');

        $add = fn (string $ip) => $this->post(route('admin.accesses.own.add'), ['endereco' => $ip]);
        $add('203.0.113.7')->assertRedirect(route('admin.accesses.ip', ['ip' => '203.0.113.7']))
            ->assertSessionHas('status', '203.0.113.7 entrou nos IPs nossos: os acessos dele, de agora e de antes, contam como nossos.');
        $add('203.0.113.7')->assertSessionHas('status', '203.0.113.7 já conta como nosso.');
        $this->assertSame(['203.0.113.7'], app(OwnAddresses::class)->edited());

        // Já nosso: a ficha diz, sem o botão.
        $this->get(route('admin.accesses.ip', ['ip' => '203.0.113.7']))->assertOk()
            ->assertSee('203.0.113.7 já conta como nosso.')
            ->assertDontSee('Contar o IP 203.0.113.7 como nosso?', false);

        $add('abc')->assertSessionHasErrors(['endereco' => 'IP inválido.']);
        $this->assertSame(['203.0.113.7'], app(OwnAddresses::class)->edited());
    }

    public function test_the_overview_has_no_destructive_button_and_counts_only_on_the_ip_page(): void
    {
        $this->visit('203.0.113.7');
        $this->visit('198.51.100.9');
        $this->actingAs($this->admin());

        $counts = function (string $route, array $query): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route($route, $query))->assertOk();
            DB::disableQueryLog();

            return collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'unnest(cast('))->pluck('bindings')->all();
        };

        $this->get(route('admin.accesses'))->assertOk()
            ->assertSee('203.0.113.7')
            ->assertDontSee('data-confirm', false)
            ->assertDontSee(route('admin.accesses.destroy-ip'))
            ->assertDontSee('IPs nossos')
            ->assertDontSee('Desligar a monitoria');

        $this->assertSame([], $counts('admin.accesses', []));
        $this->assertSame([['{"203.0.113.7"}']], $counts('admin.accesses.ip', ['ip' => '203.0.113.7']));
    }

    public function test_the_settings_tab_has_the_switch_with_a_dialog_and_the_list(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('admin.accesses.settings'))->assertOk()
            ->assertSee('Desligar a monitoria')
            ->assertSee('data-confirm="Desligar a monitoria?', false)
            ->assertSee('Um IP ou faixa (CIDR) por linha')
            ->assertSee('Adicionar o meu IP atual')
            ->assertSee('>Ajustes</a>', false);

        // O selo do cabeçalho leva para Ajustes, em todas as abas.
        $this->get(route('admin.accesses'))->assertOk()->assertSee('● gravando')->assertSee(route('admin.accesses.settings'));

        $this->from(route('admin.accesses.settings'))->post(route('admin.accesses.toggle'))->assertRedirect(route('admin.accesses.settings'));
        $this->get(route('admin.accesses.settings'))->assertSee('Ligar a monitoria')->assertDontSee('data-confirm', false);
    }

}
