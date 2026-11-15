<?php

namespace Tests\Feature;

use App\Models\PageView;
use App\Models\User;
use App\Services\Monitoring\OwnAddresses;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin → Acessos: os acessos "nossos" (a conferência do deploy, os scripts, as
 * conferências feitas de casa) levam o selo "nosso" e ficam escondidos por
 * padrão, sem apagar; a lista de IPs nossos é editada na aba Ajustes.
 */
class OwnAccessesTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        // Teste sem RefreshDatabase que abre uma página grava fora da transação e o registro fica (como no PageViewsTest).
        PageView::query()->delete();
        Settings::flush();
        config(['secmon.own_addresses' => ['203.0.113.10', '2001:db8:66:21cf::/64']]);
    }

    private function pageView(string $ip, string $agent): void
    {
        PageView::query()->create([
            'token' => (string) Str::uuid(), 'ip' => $ip, 'country' => 'BR', 'user_agent' => $agent,
            'is_bot' => ! str_contains($agent, 'Chrome'), 'path' => '/', 'status' => 200, 'created_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_our_accesses_are_hidden_by_default_and_marked_when_shown(): void
    {
        $this->pageView('127.0.0.1', 'Wget/1.21.4');            // a conferência depois do deploy
        $this->pageView('2001:db8:66:21cf::1', 'curl/8.5.0');   // o servidor, pelo IPv6 (SECMON_OWN_ADDRESSES)
        $this->pageView('172.18.0.5', 'curl/8.5.0');             // a rede interna do Docker
        $this->pageView('177.68.243.240', 'curl/8.18.0');        // a casa do dono (na lista da tela)
        $this->pageView('203.0.113.7', self::CHROME);            // um visitante
        Settings::set(OwnAddresses::SETTING, ['177.68.243.0/24']);
        $this->actingAs($this->admin());

        $default = $this->get(route('admin.accesses'))->assertOk()->assertSee('4 nosso(s) escondido(s)');
        $this->assertSame(['203.0.113.7'], $default->viewData('byIp')->pluck('ip')->all());
        $this->assertSame(1, (int) $default->viewData('totals')->views);
        $this->assertSame(1, $default->viewData('recent')->total());

        $shown = $this->get(route('admin.accesses', ['nossos' => 'mostrar']))->assertOk()->assertSee('>nosso</span>', false);
        $ours = $shown->viewData('byIp')->where('ours', true)->pluck('ip')->all();
        $this->assertEqualsCanonicalizing(['127.0.0.1', '2001:db8:66:21cf::1', '172.18.0.5', '177.68.243.240'], $ours);
        $this->assertSame(5, (int) $shown->viewData('totals')->views);
        $this->assertSame(0, (int) $shown->viewData('totals')->hidden_ours);

        $only = $this->get(route('admin.accesses', ['nossos' => 'so']))->assertOk();
        $this->assertSame(4, (int) $only->viewData('totals')->views);
        $this->assertTrue($only->viewData('recent')->every(fn (PageView $view) => $view->ours));

        $this->get(route('admin.accesses', ['nossos' => 'todos']))->assertSessionHasErrors('nossos');
        // Nada é apagado.
        $this->assertSame(5, PageView::query()->count());
    }

    public function test_the_admin_edits_the_list_in_the_settings_tab(): void
    {
        $this->actingAs($this->admin());

        $this->from(route('admin.accesses.settings'))->put(route('admin.accesses.own'), ['enderecos' => "177.68.243.240\n10.8.0.0/16, lixo 300.1.1.1"])
            ->assertRedirect(route('admin.accesses.settings'))
            ->assertSessionHasErrors(['enderecos' => 'Não é IP nem faixa (CIDR): lixo, 300.1.1.1.']);
        $this->assertSame([], app(OwnAddresses::class)->edited());

        $this->from(route('admin.accesses.settings'))->put(route('admin.accesses.own'), ['enderecos' => "177.68.243.240\r\n2804:14c::/32\n177.68.243.240"])
            ->assertRedirect(route('admin.accesses.settings'))
            ->assertSessionHas('status');
        $this->assertSame(['177.68.243.240', '2804:14c::/32'], app(OwnAddresses::class)->edited());

        $this->get(route('admin.accesses.settings'))->assertOk()->assertSee('2804:14c::/32')->assertSee('203.0.113.10, 2001:db8:66:21cf::/64');

        $this->put(route('admin.accesses.own'), ['enderecos' => ''])->assertSessionHasNoErrors();
        $this->assertSame([], app(OwnAddresses::class)->edited());
    }

    public function test_the_current_ip_of_the_admin_goes_into_the_list_once(): void
    {
        $this->actingAs($this->admin());
        $mine = fn () => $this->withServerVariables(['REMOTE_ADDR' => '177.68.250.9'])
            ->from(route('admin.accesses.settings'))->post(route('admin.accesses.own.mine'));

        $mine()->assertRedirect(route('admin.accesses.settings'))->assertSessionHas('status', '177.68.250.9 entrou nos IPs nossos: os acessos dele, de agora e de antes, contam como nossos.');
        $mine()->assertSessionHas('status', '177.68.250.9 já conta como nosso.');
        $this->assertSame(['177.68.250.9'], app(OwnAddresses::class)->edited());

        // Da rede interna (ou do servidor) já conta sozinho: a lista não muda.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->post(route('admin.accesses.own.mine'))->assertSessionHas('status', '127.0.0.1 já conta como nosso.');
        $this->assertSame(['177.68.250.9'], app(OwnAddresses::class)->edited());
    }
}
