<?php

namespace Tests\Feature;

use App\Models\PageView;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin → Acessos: a ferramenta de varredura conta como robô, e a linha com
 * cara de ataque ganha o selo da categoria em "Últimos acessos".
 */
class AccessAttacksTest extends TestCase
{
    use RefreshDatabase;

    private const NIKTO = 'Mozilla/5.00 (Nikto/2.5.0) (Evasions:None) (Test:000001)';

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        PageView::query()->delete();
    }

    private function recorded(string $path, ?string $userAgent = self::CHROME, array $columns = []): PageView
    {
        return PageView::query()->create($columns + [
            'token' => (string) Str::uuid(), 'ip' => '198.51.100.9', 'user_agent' => $userAgent, 'path' => $path,
            'is_bot' => false, 'status' => 404, 'created_at' => now(),
        ]);
    }

    public function test_nikto_and_sqlmap_are_scanners_and_curl_stays_unknown(): void
    {
        foreach ([self::NIKTO, 'sqlmap/1.8.4#stable (https://sqlmap.org)', 'curl/8.5.0'] as $userAgent) {
            $this->withHeaders(['User-Agent' => $userAgent])->get('/nao-existe-'.uniqid())->assertNotFound();
        }

        $this->assertSame(
            [[true, 'scanner'], [true, 'scanner'], [true, 'unknown']],
            PageView::query()->orderBy('id')->get()->map(fn (PageView $view) => [$view->is_bot, $view->bot_kind])->all(),
        );
    }

    public function test_the_attack_gets_a_red_seal_and_the_page_does_not(): void
    {
        $this->recorded('/?id=1+UNION+SELECT+1--');
        $this->recorded('/.env');
        $this->recorded('/sobre', columns: ['status' => 200]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.accesses'))
            ->assertOk()
            ->assertSee('injeção de SQL')
            ->assertSee('arquivo sensível')
            ->assertViewHas('recent', fn ($recent) => $recent->pluck('attack', 'path')->all() === [
                '/sobre' => null, '/.env' => 'arquivo_sensivel', '/?id=1+UNION+SELECT+1--' => 'sqli',
            ]);

        $this->get(route('admin.accesses', ['tipo' => 'scanner']))->assertOk()->assertSessionHasNoErrors();
    }

    public function test_the_migration_marks_the_old_scanners_and_cleans_the_secrets_again(): void
    {
        $nikto = $this->recorded('/wp-login.php', self::NIKTO);
        $censys = $this->recorded('/', 'Mozilla/5.0 (compatible; CensysInspect/1.1; +https://about.censys.io/)', ['is_bot' => true, 'bot_owner' => 'Censys', 'bot_kind' => 'unknown']);
        $person = $this->recorded('/sobre');
        $signed = $this->recorded('/a/1/relevante?expires=1&signature=abc');

        // Rodar de novo (e a limpeza da 2026_12_05_000100 que ela repete) não muda nada.
        $migration = require database_path('migrations/2026_12_05_000200_mark_scanners_in_page_views.php');
        $migration->up();
        $migration->up();

        $this->assertSame([true, null, 'scanner'], [$nikto->fresh()->is_bot, $nikto->fresh()->bot_owner, $nikto->fresh()->bot_kind]);
        $this->assertSame(['Censys', 'scanner'], [$censys->fresh()->bot_owner, $censys->fresh()->bot_kind]);
        $this->assertSame([false, null], [$person->fresh()->is_bot, $person->fresh()->bot_kind]);
        $this->assertSame('/a/1/relevante?expires=&signature=', $signed->fresh()->path);
    }
}
