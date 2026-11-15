<?php

namespace Tests\Feature\Alerts;

use App\Jobs\MatchCaseToProfiles;
use App\Models\AlertMatch;
use App\Models\LookalikeAlert;
use App\Models\NotificationChannel;
use App\Services\Alerts\Discord\MessageBuilder as Discord;
use App\Services\Alerts\ProfileIndex;
use App\Services\Alerts\Slack\MessageBuilder as Slack;
use App\Services\Alerts\Slack\WorkflowVariables;
use App\Services\Alerts\Telegram\MessageBuilder as Telegram;
use App\Services\Github\GithubMessages;
use App\Services\Lookalike\AlertMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Github\GithubTestHelpers;
use Tests\Feature\Lookalike\LookalikeTestHelpers;
use Tests\TestCase;

/**
 * A forma das notificações nos três apps: sem atalho para o site (ajustar
 * alertas, ver todos, preferências) e sem rodapé de fonte. Quem quiser mexer
 * nas preferências abre o site.
 */
class NotificationShapeTest extends TestCase
{
    use GithubTestHelpers, LookalikeTestHelpers, RefreshDatabase {
        LookalikeTestHelpers::client insteadof GithubTestHelpers;
        LookalikeTestHelpers::adminUser insteadof GithubTestHelpers;
        LookalikeTestHelpers::profile insteadof GithubTestHelpers;
        LookalikeTestHelpers::newsCase insteadof GithubTestHelpers;
        LookalikeTestHelpers::article insteadof GithubTestHelpers;
        LookalikeTestHelpers::slackChannel insteadof GithubTestHelpers;
    }

    /** O que nenhuma notificação pode ter. */
    private const FORBIDDEN = ['Ajustar alertas', 'ajustar alertas', 'ver todos', 'Ver todos', 'alerta da sua entrevista', 'Monitoramento do GitHub ·', 'Radar de domínios ·', 'preferências', 'Preferências', 'Notificadores'];

    private NotificationChannel $slack;

    private NotificationChannel $discord;

    private NotificationChannel $telegram;

    private AlertMatch $match;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
        Http::fake(['*' => Http::response('ok')]);

        $user = $this->client();
        $this->profile($user, ['language' => 'pt', 'min_criticity' => 'high'], ['nginx']);
        $this->slack = $this->slackChannel($user);
        $this->discord = NotificationChannel::query()->create(['user_id' => $user->id, 'type' => 'discord', 'method' => 'dm', 'status' => 'active', 'realtime' => true, 'digest' => true, 'digest_time' => '07:00', 'digest_days' => [1, 2, 3], 'timezone' => 'America/Sao_Paulo']);
        $this->telegram = NotificationChannel::query()->create(['user_id' => $user->id, 'type' => 'telegram', 'method' => 'private', 'status' => 'active', 'realtime' => true, 'digest' => true, 'digest_time' => '07:00', 'digest_days' => [1, 2, 3], 'timezone' => 'America/Sao_Paulo']);

        $case = $this->newsCase('Nginx RCE', 'critical', ['affected_products' => ['nginx 1.24'], 'cves' => ['CVE-2026-1']], ['title_pt' => 'Falha crítica no Nginx', 'summary_pt' => 'Execução remota sem login.']);
        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));
        $this->match = AlertMatch::query()->where('case_id', $case->id)->sole();
    }

    private function assertClean(array $payload, string $what): void
    {
        $text = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach (self::FORBIDDEN as $needle) {
            $this->assertStringNotContainsString($needle, $text, "{$what} não pode ter “{$needle}”");
        }
    }

    public function test_news_alert_has_no_shortcut_to_the_site_in_any_app(): void
    {
        $slack = app(Slack::class)->alert($this->match);
        $discord = app(Discord::class)->alert($this->match);
        $telegram = app(Telegram::class)->alert($this->match);

        $this->assertClean($slack, 'o alerta do Slack');
        $this->assertClean($discord, 'o alerta do Discord');
        $this->assertClean($telegram, 'o alerta do Telegram');

        // Continua com o que o cliente precisa para agir: ler e votar.
        $this->assertSame(['Ler no Security Monitor', 'Relevante', 'Não é relevante'], array_column(array_column(end($slack['blocks'])['elements'], 'text'), 'text'));
        $this->assertSame('actions', end($slack['blocks'])['type']);
        $this->assertArrayNotHasKey('footer', $discord['embeds'][0]);
        $this->assertCount(2, $telegram['reply_markup']['inline_keyboard']);
    }

    public function test_grouped_alerts_have_no_see_all_link(): void
    {
        $matches = collect([$this->match, $this->match]);
        $slack = app(Slack::class)->grouped($matches, 'quiet_hours');
        $discord = app(Discord::class)->grouped($matches, 'quiet_hours');
        $telegram = app(Telegram::class)->grouped($matches, 'quiet_hours');

        $this->assertClean($slack, 'o agrupado do Slack');
        $this->assertClean($discord, 'o agrupado do Discord');
        $this->assertClean($telegram, 'o agrupado do Telegram');
        $this->assertNotContains('actions', array_column($slack['blocks'], 'type'));
        $this->assertArrayNotHasKey('reply_markup', $telegram);
        $this->assertSame('HTML', $telegram['parse_mode']);
    }

    public function test_digest_and_test_message_have_no_settings_links(): void
    {
        $data = [
            'lang' => 'pt', 'date' => Carbon::parse('2026-10-22'), 'days' => 'de segunda a sexta', 'time' => '07:00', 'tz_label' => 'Brasília',
            'news' => true, 'total' => 3, 'counts' => ['critical' => 1, 'high' => 2], 'scope' => 'mine', 'panorama' => [], 'panorama_total' => 0,
            'mine' => [['case' => $this->match->case, 'criticity' => 'critical', 'why' => 'Nginx', 'sent_at' => '08:14']],
        ];

        foreach ([[app(Slack::class), $this->slack, 'Slack'], [app(Discord::class), $this->discord, 'Discord'], [app(Telegram::class), $this->telegram, 'Telegram']] as [$builder, $channel, $app]) {
            $this->assertClean($builder->digest($data), "o resumo do {$app}");
            $this->assertClean($builder->test($channel), "o teste do {$app}");
        }

        $this->assertCount(1, app(Telegram::class)->digest($data)['reply_markup']['inline_keyboard'][0], 'só "Abrir o feed"');
        $this->assertArrayNotHasKey('reply_markup', app(Telegram::class)->test($this->telegram));
    }

    public function test_site_lookalike_alert_has_no_footer_and_the_workflow_gets_the_print_link(): void
    {
        $watch = $this->watch($this->verifiedDomain($this->slack->user));
        $lookalike = $this->lookalike($watch, 'acmepay-login.com', ['risk' => 'critical', 'state' => 'content', 'registered_at' => now()->subDays(3)]);
        $alert = LookalikeAlert::query()->create([
            'lookalike_id' => $lookalike->id, 'kind' => 'new', 'risk' => 'critical', 'status' => 'sent', 'ai_score' => 92, 'ai_reason' => 'Copia a tela de login.',
            'photo' => ['device' => 'desktop', 'moment' => 'first', 'path' => 'lote1/5-desktop-1.jpg'],
        ]);
        $messages = app(AlertMessages::class);

        $slack = $messages->alert($alert, $this->slack);
        $discord = $messages->alert($alert, $this->discord);
        $telegram = $messages->alert($alert, $this->telegram);

        $this->assertClean($slack, 'o Radar no Slack');
        $this->assertClean($discord, 'o Radar no Discord');
        $this->assertClean($telegram, 'o Radar no Telegram');
        $this->assertArrayNotHasKey('footer', $discord['embeds'][0]);
        $this->assertContains('image', array_column($slack['blocks'], 'type'));
        $this->assertArrayHasKey('photo', $telegram);

        // Fluxo de trabalho: sem imagem, o print vai como link, junto com os links de decisão.
        $text = WorkflowVariables::from($slack)['notificacao'];
        $this->assertMatchesRegularExpression('#Print do site: https?://\S+/r/foto/\d+\?\S*signature=#', $text);
        $this->assertStringContainsString('É golpe: ', $text);
        $this->assertStringContainsString('Kit de denúncia: ', $text);
        $this->assertStringNotContainsString('ver todos', $text);
    }

    public function test_github_alert_has_no_footer_and_the_workflow_text_has_no_code_fences(): void
    {
        $finding = $this->finding($this->monitor($this->slack->user), ['ai_score' => 94, 'triage_score' => 91, 'triage_reason' => 'Chave com cara de real.']);
        $messages = app(GithubMessages::class);

        $slack = $messages->slack($finding);
        $discord = $messages->discord($finding);
        $telegram = $messages->telegram($finding);

        $this->assertClean($slack, 'o GitHub no Slack');
        $this->assertClean($discord, 'o GitHub no Discord');
        $this->assertClean($telegram, 'o GitHub no Telegram');
        $this->assertArrayNotHasKey('footer', $discord['embeds'][0]);

        $text = WorkflowVariables::from($slack)['notificacao'];
        $this->assertStringNotContainsString('```', $text);
        $this->assertStringContainsString('ACME_SITE_BR=Zq8x', $text);
        $this->assertStringContainsString('Relevante: ', $text);
        $this->assertStringContainsString('Abrir no GitHub: https://github.com/dev-fulano/teste-api/', $text);
    }
}
