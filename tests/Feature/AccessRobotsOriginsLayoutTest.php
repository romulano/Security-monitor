<?php

namespace Tests\Feature;

use App\Models\PageView;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Admin → Acessos, Robôs e Origens enxutas: os primeiros de cada quadro, com "ver mais", e o texto recolhido. */
class AccessRobotsOriginsLayoutTest extends TestCase
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

    private function visit(string $ip, string $path, array $columns = [], int $times = 1): void
    {
        foreach (range(1, $times) as $i) {
            PageView::query()->create($columns + [
                'token' => (string) Str::uuid(), 'ip' => $ip, 'user_agent' => 'Mozilla/5.0 Firefox/144.0', 'path' => $path,
                'is_bot' => false, 'status' => 200, 'duration_ms' => 30_000, 'created_at' => now()->subHour(),
            ]);
        }
    }

    public function test_robots_show_fifteen_robots_and_ten_stories_until_see_more(): void
    {
        foreach (range(1, 16) as $i) {
            $this->visit('198.51.100.'.$i, '/', ['is_bot' => true, 'bot_kind' => 'search', 'bot_owner' => sprintf('Robo-%02d', $i), 'duration_ms' => null]);
        }
        $uuids = [];
        foreach (range(1, 11) as $i) {
            $uuids[$i] = (string) Str::uuid();
            $this->visit('20.1.1.'.$i, '/materia/'.$uuids[$i], ['is_bot' => true, 'bot_kind' => 'ai_user', 'bot_owner' => 'OpenAI', 'duration_ms' => null], 12 - $i);
        }

        $this->get(route('admin.accesses.robots'))->assertOk()
            ->assertSee('Por que um robô pode ser falso')
            ->assertSee('o que o robô declara no navegador (User-Agent)')
            // O OpenAI das matérias (66 acessos) vem primeiro: os 15 são ele e Robo-01 a Robo-14.
            ->assertSee('Robo-14')->assertDontSee('Robo-15')
            ->assertSee($uuids[10])->assertDontSee($uuids[11])
            ->assertSee('Ver os 17 robôs ›')->assertSee('Ver as 11 matérias ›');

        $this->get(route('admin.accesses.robots', ['mais' => 'robos']))->assertOk()->assertSee('Robo-16')->assertSee('Ver menos')->assertDontSee($uuids[11]);
        $this->get(route('admin.accesses.robots', ['mais' => 'ias']))->assertOk()->assertSee($uuids[11])->assertDontSee('Robo-16');
        $this->get(route('admin.accesses.robots', ['mais' => 'paginas']))->assertSessionHasErrors('mais');
    }

    public function test_origins_show_ten_rows_per_table_until_see_more_and_no_campaigns(): void
    {
        foreach (range(1, 11) as $i) {
            $this->visit('203.0.113.'.$i, '/sobre', ['referer' => 'https://site-'.$i.'.example/'], 12 - $i);
        }

        $this->get(route('admin.accesses.origins'))->assertOk()
            ->assertSee('site-10.example')->assertDontSee('site-11.example')
            ->assertSee('Ver todos os 11 ›')
            ->assertDontSee('O utm_source e o utm_campaign')
            ->assertDontSee('Campanhas (utm) ficam em');

        $this->get(route('admin.accesses.origins', ['mais' => 'sites']))->assertOk()->assertSee('site-11.example')->assertSee('Ver menos');
        $this->get(route('admin.accesses.origins', ['mais' => 'robos']))->assertSessionHasErrors('mais');
    }
}
