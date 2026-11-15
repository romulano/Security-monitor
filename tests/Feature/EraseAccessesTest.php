<?php

namespace Tests\Feature;

use App\Models\DeviceSighting;
use App\Models\PageView;
use App\Models\User;
use App\Support\Settings;
use App\Support\UserAgents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin → Acessos (2026-11-01): apagar um acesso ou tudo de um IP (o período
 * inteiro, não só o recorte), na ficha do IP; e os robôs que passavam como
 * gente (o leitor em voz alta do Google, o Nutch).
 */
class EraseAccessesTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';

    private const READ_ALOUD = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36 (compatible; Google-Read-Aloud; +https://support.google.com/webmasters/answer/1061943)';

    private const NUTCH = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36/Nutch-1.23-SNAPSHOT';

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        // Teste sem RefreshDatabase que abre uma página grava fora da transação e o registro fica (como no PageViewsTest).
        PageView::query()->delete();
    }

    private function visit(string $ip, string $path = '/', ?string $userAgent = self::CHROME, ?\DateTimeInterface $at = null): PageView
    {
        $robot = UserAgents::robot($userAgent);

        return PageView::query()->create([
            'token' => (string) Str::uuid(), 'ip' => $ip, 'country' => 'BR', 'user_agent' => $userAgent,
            'is_bot' => $robot !== null, 'bot_owner' => $robot['owner'] ?? null, 'bot_kind' => $robot['kind'] ?? null,
            'path' => $path, 'status' => 200, 'created_at' => $at ?? now(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_the_admin_erases_one_access_from_the_ip_page_and_comes_back_to_it(): void
    {
        $keep = $this->visit('203.0.113.7', '/a');
        $gone = $this->visit('203.0.113.7', '/b');
        $this->actingAs($this->admin());

        // O × fica na linha do tempo da ficha, com o diálogo, e leva o período da ficha.
        $this->get(route('admin.accesses.ip', ['ip' => '203.0.113.7']))->assertOk()
            ->assertSee('aria-label="Apagar este acesso"', false)
            ->assertSee(e(route('admin.accesses.destroy', ['view' => $gone->id, 'periodo' => '180d'])), false);

        $this->delete(route('admin.accesses.destroy', ['view' => $gone->id, 'periodo' => '180d']))
            ->assertRedirect(route('admin.accesses.ip', ['ip' => '203.0.113.7', 'periodo' => '180d']))
            ->assertSessionHas('status', '1 registro apagado.');

        $this->assertSame([$keep->id], PageView::query()->pluck('id')->all());
    }

    public function test_the_admin_erases_every_access_of_an_ip_in_the_whole_period(): void
    {
        $this->visit('203.0.113.7', '/a');
        $this->visit('203.0.113.7', '/b');
        // Fora do recorte de 24 h da tela, mas guardado: também sai.
        $this->visit('203.0.113.7', '/antiga', at: now()->subDays(20));
        $other = $this->visit('198.51.100.9');
        // Os códigos de aparelho vistos no IP saem junto (DeviceSightings); os de outro IP ficam.
        foreach ([['a', '203.0.113.7'], ['b', '203.0.113.7'], ['a', '198.51.100.9']] as [$code, $ip]) {
            DeviceSighting::query()->create(['fingerprint' => str_repeat($code, 64), 'ip' => $ip, 'day' => now()->toDateString(), 'first_seen' => now(), 'last_seen' => now()]);
        }
        $this->actingAs($this->admin());

        // O botão fica na zona de perigo da ficha, e o diálogo diz quantos sairão, contando o de fora do período.
        $this->get(route('admin.accesses.ip', ['ip' => '203.0.113.7', 'periodo' => '24h']))->assertOk()
            ->assertSee('Apagar tudo do IP 203.0.113.7? Saem 3 acessos, 0 eventos e 2 códigos de aparelho, de todo o período guardado (não só deste filtro).', false)
            ->assertSee('Apagar tudo deste IP')
            ->assertSee('data-confirm', false);
        $this->get(route('admin.accesses'))->assertOk()->assertDontSee('Apagar tudo deste IP');

        // A ficha fica vazia: volta para a Visão geral.
        $this->delete(route('admin.accesses.destroy-ip'), ['endereco' => '203.0.113.7'])
            ->assertRedirect(route('admin.accesses'))
            ->assertSessionHas('status', '3 registros apagados e 2 códigos de aparelho (o IP 203.0.113.7).');

        $this->assertSame([$other->id], PageView::query()->pluck('id')->all());
        $this->assertSame(['198.51.100.9'], DeviceSighting::query()->pluck('ip')->all());

        $this->delete(route('admin.accesses.destroy-ip'), [])->assertSessionHasErrors('endereco');
        $this->delete(route('admin.accesses.destroy-ip'), ['endereco' => 'abc'])->assertSessionHasErrors(['endereco' => 'IP inválido.']);
    }

    public function test_only_the_admin_erases(): void
    {
        $view = $this->visit('203.0.113.7');

        $this->delete(route('admin.accesses.destroy', ['view' => $view->id]))->assertRedirect();
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->delete(route('admin.accesses.destroy', ['view' => $view->id]))->assertForbidden();
        $this->delete(route('admin.accesses.destroy-ip'), ['endereco' => '203.0.113.7'])->assertForbidden();

        $this->assertSame(1, PageView::query()->count());
    }

    public function test_erasing_needs_the_csrf_token(): void
    {
        $view = $this->visit('203.0.113.7');
        $this->actingAs($this->admin());

        // Nos testes o Laravel não confere o token; fora do "testing" ele confere.
        $this->app['env'] = 'local';

        try {
            $this->delete(route('admin.accesses.destroy', ['view' => $view->id]))->assertStatus(419);
            $this->delete(route('admin.accesses.destroy-ip'), ['endereco' => '203.0.113.7'])->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(1, PageView::query()->count());
    }

    public function test_google_read_aloud_and_nutch_are_robots_and_old_accesses_follow(): void
    {
        $this->assertSame(['Google', 'ai_user'], array_values(array_slice(UserAgents::robot(self::READ_ALOUD), 0, 2)));
        $this->assertSame(['Apache Nutch (coletor genérico)', 'unknown'], array_values(array_slice(UserAgents::robot(self::NUTCH), 0, 2)));
        $this->assertNull(UserAgents::robot(self::CHROME));

        // Como foram gravados antes: passavam por gente (com 5 s na página, para o Chrome ser pessoa pela regra do 1 s).
        foreach ([self::READ_ALOUD, self::NUTCH, self::CHROME] as $i => $userAgent) {
            DB::table('page_views')->insert([
                'token' => (string) Str::uuid(), 'ip' => '66.249.93.'.$i, 'user_agent' => $userAgent,
                'is_bot' => false, 'path' => '/', 'status' => 200, 'duration_ms' => 5000, 'created_at' => now(),
            ]);
        }

        $migration = require database_path('migrations/2026_11_24_000100_mark_more_robots_in_page_views.php');
        $migration->up();

        $this->assertSame([
            [true, 'Google', 'ai_user'],
            [true, 'Apache Nutch (coletor genérico)', 'unknown'],
            [false, null, null],
        ], PageView::query()->orderBy('id')->get()->map(fn (PageView $view) => [$view->is_bot, $view->bot_owner, $view->bot_kind])->all());

        // Saem de "Só pessoas"; o Chrome fica.
        $people = $this->actingAs($this->admin())->get(route('admin.accesses', ['robos' => 'sem']))->assertOk();
        $this->assertSame(['66.249.93.2'], $people->viewData('byIp')->pluck('ip')->all());
    }
}
