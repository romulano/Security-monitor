<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\PageView;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Admin → Acessos: o link assinado, o code do OAuth e o e-mail não ficam
 * gravados, nem os de antes (a migration), e o texto estranho não zera o campo.
 */
class PageViewSecretsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        PageView::query()->delete();
    }

    private function open(string $path, array $headers = []): TestResponse
    {
        return $this->withHeaders($headers + ['User-Agent' => self::CHROME])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->get($path);
    }

    public function test_the_signed_link_is_recorded_as_the_route_and_the_oauth_code_without_value(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $this->open(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['user' => $user->id, 'hash' => sha1($user->email)]));
        $this->open('/a/999999/nao-relevante?expires=1&signature=forjada');
        $this->open('/login/github/callback?code=abc123&state=xyz');

        $this->assertSame(
            ['/conta/confirmar-email/{user}/{hash}', '/a/{match}/nao-relevante', '/login/github/callback?code=&state='],
            PageView::query()->orderBy('id')->pluck('path')->all(),
        );
        $this->assertSame(0, PageView::query()->where('path', 'like', '%'.sha1($user->email).'%')->count());
    }

    public function test_the_referer_inside_the_site_is_cleaned_and_from_outside_loses_the_query(): void
    {
        $this->open('/sobre', ['Referer' => url('/a/9/nao-relevante').'?expires=1&signature=abc']);
        $this->open('/termos', ['Referer' => 'https://www.google.com/search?q=secmon']);

        $this->assertSame(
            [url('/a/9/nao-relevante').'?expires=&signature=', 'https://www.google.com/search'],
            PageView::query()->orderBy('id')->pluck('referer')->all(),
        );
    }

    public function test_a_broken_byte_or_an_invisible_character_does_not_wipe_the_browser(): void
    {
        $this->open('/sobre', ['User-Agent' => "sqlmap\xff"]);
        $this->open('/termos', ['User-Agent' => "Mozilla\u{202E}gpj.exe\u{200B}"]);

        $this->assertSame(['sqlmap?', 'Mozilla gpj.exe'], PageView::query()->orderBy('id')->pluck('user_agent')->all());
    }

    public function test_the_migration_cleans_what_was_recorded_and_leaves_the_rest(): void
    {
        $hash = sha1('ana@exemplo.com');
        $rows = [
            ['/login/github/callback?code=abc&state=xyz', null, '/login/github/callback?code=&state=', null],
            ['/conta/confirmar-email/7/'.$hash.'?expires=1&signature=abc', null, '/conta/confirmar-email/{user}/{hash}', null],
            ['/sobre', 'https://example.com/a/1/relevante?%73ignature=abc', '/sobre', 'https://example.com/a/1/relevante?%73ignature='],
            ['/busca?q=union+select&page=2', 'https://www.google.com/search?q=secmon', '/busca?q=union+select&page=2', 'https://www.google.com/search?q=secmon'],
        ];

        foreach ($rows as [$path, $referer]) {
            PageView::query()->create([
                'token' => (string) Str::uuid(), 'ip' => '198.51.100.9', 'path' => $path, 'referer' => $referer,
                'is_bot' => false, 'status' => 200, 'created_at' => now(),
            ]);
        }

        $migration = require database_path('migrations/2026_12_05_000100_redact_secrets_in_page_views.php');
        $migration->up();
        $migration->up();

        $this->assertSame(
            array_map(fn (array $row) => [$row[2], $row[3]], $rows),
            PageView::query()->orderBy('id')->get(['path', 'referer'])->map(fn (PageView $view) => [$view->path, $view->referer])->all(),
        );
    }

    public function test_the_invite_leaves_the_accesses_and_the_events(): void
    {
        $view = PageView::query()->create([
            'token' => (string) Str::uuid(), 'ip' => '198.51.100.9', 'path' => '/cadastro?convite=ffeaaf5b0727202642ebcbb7d32224d7',
            'referer' => 'https://example.com/cadastro?convite=abc&utm_source=linkedin', 'is_bot' => false, 'status' => 200, 'created_at' => now(),
        ]);
        $event = AccessEvent::query()->create([
            'created_at' => now(), 'ip' => '198.51.100.9', 'method' => 'POST', 'path' => '/cadastro?convite=abc', 'status' => 422,
        ]);

        $migration = require database_path('migrations/2026_12_05_000800_redact_invites_in_accesses.php');
        $migration->up();
        $migration->up();

        $this->assertSame(['/cadastro?convite=', 'https://example.com/cadastro?convite=&utm_source=linkedin'], [$view->fresh()->path, $view->fresh()->referer]);
        $this->assertSame('/cadastro?convite=', $event->fresh()->path);
    }
}
