<?php

namespace Tests\Feature;

use App\Models\PageView;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Admin → Acessos, Visão geral enxuta: páginas e IPs logo depois da barra (10 de cada, com "ver mais"), o log no fim. */
class AccessOverviewLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        PageView::query()->delete();
        $this->travelTo(Carbon::parse('2026-11-04 12:00:00', 'UTC'));
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function visit(string $ip, string $path, int $times = 1, int $hoursAgo = 0): void
    {
        foreach (range(1, $times) as $i) {
            PageView::query()->create([
                'token' => (string) Str::uuid(), 'ip' => $ip, 'country' => 'BR', 'user_agent' => 'Mozilla/5.0 Firefox/144.0', 'path' => $path,
                'is_bot' => false, 'status' => 200, 'duration_ms' => 5000, 'created_at' => now()->subHours($hoursAgo)->subMinutes($i),
            ]);
        }
    }

    public function test_the_sections_come_in_the_new_order_without_the_old_cards(): void
    {
        $this->visit('203.0.113.7', '/sobre');

        $page = $this->get(route('admin.accesses'))->assertOk()
            ->assertSeeInOrder(['Páginas mais abertas', 'Quem mais acessou', 'horário de Brasília', 'Países', 'Últimos acessos'])
            ->assertSee('Robôs ›')
            ->assertSee('Erros ›')
            ->assertDontSee('Eventos de segurança')
            ->assertDontSee('IPs diferentes');

        $this->assertSame(25, $page->viewData('recent')->perPage());
        // Os Últimos acessos não têm mais a coluna Navegador (o navegador vai no title do IP).
        $html = $page->getContent();
        $this->assertStringNotContainsString('Navegador', substr($html, strpos($html, 'Últimos acessos')));
        $this->assertStringContainsString('title="Mozilla/5.0 Firefox/144.0"', $html);
    }

    public function test_ten_pages_and_ten_ips_until_see_more(): void
    {
        // 12 páginas e 12 IPs, as menos abertas mais antigas: a 12ª fica fora dos 25 Últimos acessos e das 10 da seção.
        foreach (range(1, 12) as $i) {
            $this->visit('198.51.100.'.$i, '/pagina-'.$i, 13 - $i, $i);
        }

        $this->get(route('admin.accesses'))->assertOk()
            ->assertSee('title="/pagina-10"', false)->assertDontSee('/pagina-12')
            ->assertSee('Ver as 12 páginas ›')->assertSee('Ver os 12 IPs ›')
            ->assertSee(e(route('admin.accesses', ['mais' => 'paginas'])).'#paginas', false);

        $this->get(route('admin.accesses', ['mais' => 'paginas']))->assertOk()
            ->assertSee('title="/pagina-12"', false)
            ->assertSee('Ver menos')
            ->assertSee('Ver os 12 IPs ›');

        $ips = fn (array $query) => substr_count($this->get(route('admin.accesses', $query))->getContent(), '>198.51.100.12</a>');
        $this->assertSame([0, 1], [$ips([]), $ips(['mais' => 'ips'])]);
    }

    public function test_see_more_is_validated_and_stays_out_of_the_tabs(): void
    {
        $this->get(route('admin.accesses', ['mais' => 'x']))->assertSessionHasErrors('mais');

        $this->get(route('admin.accesses', ['mais' => 'paises', 'pais' => 'BR']))->assertOk()
            ->assertSee('href="'.e(route('admin.accesses.robots', ['pais' => 'BR'])).'"', false)
            ->assertDontSee('robos?pais=BR&amp;mais', false);
    }
}
