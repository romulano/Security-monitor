<?php

namespace Tests\Feature;

use App\Models\PageView;
use App\Support\Settings;
use App\Support\UserAgents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Alerts\AlertsTestHelpers;
use Tests\TestCase;

/**
 * Admin → Acessos (2026-11-01): de quem é o robô (OpenAI, Google, Slack...) e
 * para que ele lê o site, pelo que declara no navegador; o "nosso" é pelo IP.
 * Filtro por tipo e por dono, o quadro por dono e tipo e as matérias que as IAs
 * abriram a pedido de alguém.
 */
class RobotOwnersTest extends TestCase
{
    use AlertsTestHelpers;
    use RefreshDatabase;

    private const CHATGPT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot';

    private const PERPLEXITY = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)';

    private const CLAUDE = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0; +Claude-User@anthropic.com)';

    private const SEARCHBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot';

    private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    private const SLACK = 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)';

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        // Teste sem RefreshDatabase que abre uma página grava fora da transação e o registro fica (como no PageViewsTest).
        PageView::query()->delete();
    }

    /** Um acesso como o PageViewRecorder grava. */
    private function visit(string $ip, ?string $userAgent, string $path, ?int $duration = null): void
    {
        $robot = UserAgents::robot($userAgent);

        PageView::query()->create([
            'token' => (string) Str::uuid(), 'ip' => $ip, 'country' => 'US', 'user_agent' => $userAgent,
            'is_bot' => $robot !== null, 'bot_owner' => $robot['owner'] ?? null, 'bot_kind' => $robot['kind'] ?? null,
            'path' => $path, 'status' => 200, 'duration_ms' => $duration, 'created_at' => now(),
        ]);
    }

    public function test_each_page_view_keeps_whose_robot_it_was(): void
    {
        $open = fn (string $userAgent) => $this->withHeaders(['User-Agent' => $userAgent])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->get('/sobre')
            ->assertOk();

        $open(self::CHATGPT);
        $open(self::CHROME);
        // O WhatsApp não tem "bot" no nome: o teste antigo o contava como gente.
        $open('WhatsApp/2.23.20.0 A');

        $this->assertSame([
            [true, 'OpenAI', 'ai_user'],
            [false, null, null],
            [true, 'WhatsApp', 'preview'],
        ], PageView::query()->orderBy('id')->get()->map(fn (PageView $view) => [$view->is_bot, $view->bot_owner, $view->bot_kind])->all());
    }

    public function test_accesses_show_the_owner_and_the_type_with_filters_and_the_summary(): void
    {
        $story = $this->newsCase('Nginx RCE exploited in the wild');
        $other = $this->newsCase('Ivanti zero-day');

        $this->visit('20.1.1.1', self::CHATGPT, '/materia/'.$story->uuid);
        $this->visit('20.1.1.1', self::CHATGPT, '/materia/'.$story->uuid.'?utm_source=chatgpt.com');
        $this->visit('20.1.1.2', self::PERPLEXITY, '/materia/'.$story->uuid);
        $this->visit('20.1.1.3', self::CLAUDE, '/materia/'.$other->uuid);
        $this->visit('20.1.1.4', self::SEARCHBOT, '/materia/'.$other->uuid);
        $this->visit('66.249.66.1', self::GOOGLEBOT, '/');
        $this->visit('66.249.66.1', self::GOOGLEBOT, '/sobre');
        $this->visit('3.3.3.3', self::SLACK, '/materia/'.$story->uuid);
        $this->visit('5.5.5.5', 'Mozilla/5.0 (compatible; SomeNewCrawler/0.1)', '/');
        // O deploy confere o site com o curl, de dentro: é nosso pelo IP, não pelo nome.
        $this->visit('127.0.0.1', 'curl/8.5.0', '/');
        $this->visit('203.0.113.7', self::CHROME, '/', 41000);

        $this->actingAs($this->adminUser());

        $page = $this->get(route('admin.accesses.robots'))
            ->assertOk()
            ->assertSee('Robôs por dono e tipo')
            ->assertSee('o que o robô declara no navegador (User-Agent)')
            ->assertSee('IA a pedido de alguém')
            ->assertSee('Perplexity')
            ->assertSee('O que as IAs abriram a pedido de alguém')
            ->assertSee('Nginx RCE exploited in the wild');

        $byRobot = $page->viewData('byRobot')->map(fn ($row) => [$row->owner, $row->kind, (int) $row->views, (int) $row->stories])->all();
        $this->assertEqualsCanonicalizing([
            ['OpenAI', 'ai_user', 2, 1],
            ['Perplexity', 'ai_user', 1, 1],
            ['Anthropic', 'ai_user', 1, 1],
            ['OpenAI', 'ai_search', 1, 1],
            ['Google', 'search', 2, 0],
            ['Slack', 'preview', 1, 1],
            [null, 'unknown', 1, 0],
        ], $byRobot, 'o curl de dentro é nosso, e os nossos ficam escondidos por padrão');

        // A matéria mais aberta pelas IAs a pedido de alguém vem primeiro, com o título e quem abriu.
        $this->assertSame([
            [$story->uuid, 'Nginx RCE exploited in the wild', 3, 'OpenAI, Perplexity'],
            [$other->uuid, 'Ivanti zero-day', 1, 'Anthropic'],
        ], $page->viewData('aiAsked')->map(fn ($row) => [$row->uuid, $row->title, (int) $row->views, $row->owners])->all());

        // O dono escolhido vira chip com ×, e o clique na tabela filtra a própria aba Robôs.
        $this->get(route('admin.accesses.robots', ['dono' => 'OpenAI']))->assertOk()
            ->assertSee('Dono OpenAI')
            ->assertSee('aria-label="Tirar o filtro Dono OpenAI"', false);
        $page->assertSee('href="'.e(route('admin.accesses.robots', ['tipo' => 'search', 'dono' => 'Google'])).'"', false);

        // Por tipo, por dono, e o nosso pelo IP.
        $this->assertSame(4, $this->get(route('admin.accesses', ['tipo' => 'ai_user']))->assertOk()->viewData('recent')->total());
        $this->assertSame(3, $this->get(route('admin.accesses', ['dono' => 'OpenAI']))->assertOk()->viewData('recent')->total());
        $ours = $this->get(route('admin.accesses', ['nossos' => 'mostrar', 'tipo' => 'ours']))->assertOk()->assertSee('Tipo nosso');
        $this->assertSame(['Security Monitor (nosso)'], $ours->viewData('recent')->getCollection()->pluck('robot_owner')->all());
        $this->assertSame(['127.0.0.1'], $ours->viewData('byIp')->pluck('ip')->all());
        // Pedir o robô nosso com o padrão (esconder os nossos) mostra os nossos, em vez de zerar.
        $default = $this->get(route('admin.accesses', ['dono' => 'Security Monitor (nosso)']))->assertOk()->assertSee('Dono Security Monitor (nosso)')->assertDontSee('Nossos à mostra');
        $this->assertSame(['127.0.0.1'], $default->viewData('byIp')->pluck('ip')->all());
        $this->assertSame(['127.0.0.1'], $this->get(route('admin.accesses', ['tipo' => 'ours']))->assertOk()->viewData('byIp')->pluck('ip')->all());

        // "Só pessoas" fica só com o Chrome.
        $people = $this->get(route('admin.accesses', ['robos' => 'sem']))->assertOk();
        $this->assertSame(['203.0.113.7'], $people->viewData('byIp')->pluck('ip')->all());
        // Na aba Robôs o "Quem" não vale: "Só pessoas" não zera a tabela.
        $this->assertCount(7, $this->get(route('admin.accesses.robots', ['robos' => 'sem']))->assertOk()->viewData('byRobot'));

        $this->get(route('admin.accesses', ['tipo' => 'qualquer']))->assertSessionHasErrors('tipo');
    }

    public function test_the_old_page_views_are_filled_from_the_browser_they_already_have(): void
    {
        // Como o código de antes gravava: só o is_bot, e o Claude-User passava por gente.
        foreach ([self::CLAUDE, self::GOOGLEBOT, self::CHROME, null] as $i => $userAgent) {
            DB::table('page_views')->insert([
                'token' => (string) Str::uuid(), 'ip' => '198.51.100.'.$i, 'user_agent' => $userAgent,
                'is_bot' => in_array($userAgent, [self::GOOGLEBOT, null], true), 'path' => '/', 'status' => 200, 'created_at' => now(),
            ]);
        }

        $migration = require database_path('migrations/2026_11_22_000200_fill_robot_owner_in_page_views.php');
        $migration->up();

        $rows = fn () => PageView::query()->orderBy('id')->get()->map(fn (PageView $view) => [$view->is_bot, $view->bot_owner, $view->bot_kind])->all();
        $this->assertSame([
            [true, 'Anthropic', 'ai_user'],
            [true, 'Google', 'search'],
            [false, null, null],
            [true, null, 'unknown'],
        ], $rows());

        $migration->down();
        $this->assertSame([[true, null, null], [true, null, null], [false, null, null], [true, null, null]], $rows());
    }
}
