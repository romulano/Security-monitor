<?php

namespace Tests\Feature\Alerts;

use App\Console\Commands\TelegramUpdatesCommand;
use App\Jobs\MatchCaseToProfiles;
use App\Models\AlertMatch;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Alerts\ProfileIndex;
use App\Services\Alerts\Telegram\UpdateHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Telegram: conectar pelo link de uso único (conversa privada ou grupo), entregar junto com os outros apps e desconectar. */
class TelegramIntegrationTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    private const TOKEN = '123456789:token-do-bot';

    private const API = 'https://api.telegram.org/bot'.self::TOKEN;

    private const PRIVATE = '987654321';

    private const GROUP = '-1001234567890';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function configure(): void
    {
        config(['secmon.alerts.telegram.bot_token' => self::TOKEN, 'secmon.alerts.telegram.bot_username' => 'SecurityMonitorDevBot']);
    }

    private function fakeTelegram(array $extra = []): void
    {
        Http::fake($extra + [
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            'hooks.slack.com/*' => Http::response('ok'),
        ]);
    }

    private function sentTo(string $chatId, string $method = 'sendMessage'): \Closure
    {
        return fn (Request $r) => $r->url() === self::API.'/'.$method && (string) ($r['chat_id'] ?? '') === $chatId;
    }

    /** Gera o link pela tela e devolve o código que vai no /start. */
    private function startLink(User $user, string $mode = 'privado'): string
    {
        $this->actingAs($user)->post(route('alerts.telegram.connect'), ['modo' => $mode])->assertRedirect(route('alerts.telegram'));
        $link = session('telegram_link');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $link['code']);

        return $link['code'];
    }

    private function message(string $text, array $chat, array $from = []): array
    {
        return ['update_id' => random_int(1, 1_000_000), 'message' => [
            'message_id' => 10,
            'chat' => $chat,
            'from' => $from + ['id' => (int) self::PRIVATE, 'is_bot' => false, 'first_name' => 'Rômulo', 'username' => 'romulo'],
            'text' => $text,
        ]];
    }

    private function privateChat(): array
    {
        return ['id' => (int) self::PRIVATE, 'type' => 'private', 'first_name' => 'Rômulo', 'username' => 'romulo'];
    }

    private function groupChat(): array
    {
        return ['id' => (int) self::GROUP, 'type' => 'supergroup', 'title' => 'SOC da Acme'];
    }

    private function telegramChannel(User $user, array $attributes = []): NotificationChannel
    {
        return NotificationChannel::query()->create($attributes + [
            'user_id' => $user->id,
            'type' => 'telegram',
            'method' => 'private',
            'telegram_chat_id' => self::PRIVATE,
            'telegram_username' => '@romulo',
            'status' => 'active',
            'realtime' => true,
            'digest' => true,
            'digest_time' => '07:00',
            'digest_days' => [1, 2, 3, 4, 5, 6, 7],
            'timezone' => 'America/Sao_Paulo',
        ]);
    }

    private function alertFor(User $user, string $title = 'Nginx RCE', string $criticity = 'critical'): AlertMatch
    {
        $case = $this->newsCase($title, $criticity, ['affected_products' => ['nginx 1.24'], 'cves' => ['CVE-2026-1']], ['title_pt' => $title]);

        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

        return AlertMatch::query()->where('case_id', $case->id)->sole();
    }

    public function test_without_token_the_page_says_it_is_unavailable(): void
    {
        $this->actingAs($this->client());

        $this->get(route('alerts.telegram'))
            ->assertOk()
            ->assertSee('Indisponível: o bot do Security Monitor no Telegram ainda não foi configurado')
            ->assertDontSee(route('alerts.telegram.connect'));
        $this->post(route('alerts.telegram.connect'), ['modo' => 'privado'])->assertNotFound();
    }

    public function test_private_chat_connects_with_the_one_time_link(): void
    {
        $this->configure();
        $this->fakeTelegram();
        $user = $this->client();
        $code = $this->startLink($user);

        $this->get(route('alerts.telegram'))
            ->assertOk()
            ->assertSee('https://t.me/SecurityMonitorDevBot?start='.$code, false)
            ->assertSee('data-telegram-wait', false);
        $this->getJson(route('alerts.telegram.status'))->assertExactJson(['waiting' => true]);

        app(UpdateHandler::class)->handle($this->message('/start '.$code, $this->privateChat()));

        $channel = $user->telegramChannel()->sole();
        $this->assertSame(['private', self::PRIVATE, '@romulo', 'active'], [$channel->method, $channel->telegram_chat_id, $channel->telegram_username, $channel->status]);
        Http::assertSent(fn (Request $r) => $this->sentTo(self::PRIVATE)($r)
            && $r['parse_mode'] === 'HTML'
            && str_contains($r['text'], 'Esta conversa está conectada')
            && ! isset($r->data()['reply_markup']));
        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame(['test', 'sent', 'Mensagem de teste'], [$delivery->kind, $delivery->status, $delivery->summary]);

        // A página para de esperar e, ao recarregar, mostra a conversa conectada.
        $this->getJson(route('alerts.telegram.status'))->assertExactJson(['waiting' => false]);
        $this->get(route('alerts.telegram'))
            ->assertSee('Telegram conectado. Mandamos uma mensagem de teste para a conversa.')
            ->assertSee('Conversa privada com @romulo')
            ->assertDontSee('data-telegram-wait', false);
        $this->assertNull(session('telegram_link'));
    }

    public function test_group_connects_and_the_link_works_only_once(): void
    {
        $this->configure();
        $this->fakeTelegram();
        $user = $this->client();
        $code = $this->startLink($user, 'grupo');

        $this->get(route('alerts.telegram'))->assertSee('https://t.me/SecurityMonitorDevBot?startgroup='.$code, false);

        app(UpdateHandler::class)->handle($this->message('/start@SecurityMonitorDevBot '.$code, $this->groupChat()));

        $channel = $user->telegramChannel()->sole();
        $this->assertSame(['group', self::GROUP, 'SOC da Acme'], [$channel->method, $channel->telegram_chat_id, $channel->telegram_chat_title]);
        $this->assertSame('grupo SOC da Acme', $channel->label());
        Http::assertSent(fn (Request $r) => $this->sentTo(self::GROUP)($r) && str_contains($r['text'], 'Este grupo está conectado'));

        // O mesmo código de novo, em outra conversa: não conecta nada.
        app(UpdateHandler::class)->handle($this->message('/start '.$code, $this->privateChat()));

        $this->assertSame(self::GROUP, $channel->fresh()->telegram_chat_id);
        Http::assertSent(fn (Request $r) => $this->sentTo(self::PRIVATE)($r) && str_contains($r['text'], 'expirou ou já foi usado'));
    }

    public function test_start_without_code_and_other_bots_commands(): void
    {
        $this->configure();
        $this->fakeTelegram();

        app(UpdateHandler::class)->handle($this->message('/start', $this->privateChat()));
        app(UpdateHandler::class)->handle($this->message('/start@OutroBot abc', $this->groupChat()));
        app(UpdateHandler::class)->handle($this->message('bom dia, pessoal', $this->groupChat()));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $this->sentTo(self::PRIVATE)($r) && str_contains($r['text'], 'Alertas → Notificadores → Telegram'));
        $this->assertSame(0, NotificationChannel::query()->count());
    }

    public function test_expired_link_stops_waiting_and_says_so(): void
    {
        $this->configure();
        $this->fakeTelegram();
        $user = $this->client();
        $code = $this->startLink($user);

        $this->travel(31)->minutes();

        $this->getJson(route('alerts.telegram.status'))->assertExactJson(['waiting' => false]);
        $this->get(route('alerts.telegram'))->assertSee('O link de conexão expirou. Gere outro abaixo.');

        app(UpdateHandler::class)->handle($this->message('/start '.$code, $this->privateChat()));
        $this->assertNull($user->telegramChannel()->first());
    }

    public function test_cancel_invalidates_the_link(): void
    {
        $this->configure();
        $this->fakeTelegram();
        $user = $this->client();
        $code = $this->startLink($user);

        $this->delete(route('alerts.telegram.cancel'))->assertRedirect(route('alerts.telegram'));
        $this->get(route('alerts.telegram'))->assertDontSee('O link de conexão expirou');

        app(UpdateHandler::class)->handle($this->message('/start '.$code, $this->privateChat()));
        $this->assertNull($user->telegramChannel()->first());
    }

    public function test_realtime_alert_goes_to_slack_and_telegram(): void
    {
        $this->configure();
        $this->fakeTelegram();
        $user = $this->client();
        $this->profile($user, ['language' => 'pt'], ['nginx']);
        $this->slackChannel($user);
        $this->telegramChannel($user);

        $match = $this->alertFor($user);

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://hooks.slack.com/'));
        Http::assertSent(function (Request $r) use ($match) {
            $buttons = collect($r['reply_markup']['inline_keyboard'] ?? [])->flatten(1);

            return $this->sentTo(self::PRIVATE)($r)
                && str_starts_with($r['text'], '🔴 <b>CRITICAL</b> · novo alerta do Security Monitor')
                && str_contains($r['text'], '<b>Nginx RCE</b>')
                && str_contains($r['text'], 'Por que você recebeu: <b>Nginx')
                && str_contains($r['text'], '<b>CVE:</b> CVE-2026-1')
                && $r['link_preview_options'] === ['is_disabled' => true]
                && $buttons->firstWhere('text', 'Ler no Security Monitor')['url'] === route('feed.show', $match->case->uuid)
                && str_contains($buttons->firstWhere('text', '👍 Relevante')['url'], '/relevante?')
                && str_contains($buttons->firstWhere('text', '👎 Não é relevante')['url'], 'signature=');
        });

        $this->assertSame('sent', $match->fresh()->delivery_status);
        $this->assertSame(2, NotificationDelivery::query()->where('kind', 'realtime')->where('status', 'sent')->count());
        $this->assertSame('🔴 CRITICAL · novo alerta do Security Monitor', NotificationDelivery::query()->whereRelation('channel', 'type', 'telegram')->value('summary'));
        $this->actingAs($user)->get(route('alerts.news'))->assertSee('no Telegram e no Slack, na hora');
    }

    public function test_news_text_is_escaped(): void
    {
        $this->configure();
        $this->fakeTelegram();
        $user = $this->client();
        $this->profile($user, ['language' => 'pt'], ['nginx']);
        $this->telegramChannel($user);

        $this->alertFor($user, 'Nginx <script> & "aspas"');

        Http::assertSent(fn (Request $r) => $this->sentTo(self::PRIVATE)($r)
            && str_contains($r['text'], 'Nginx &lt;script&gt; &amp; &quot;aspas&quot;')
            && ! str_contains($r['text'], '<script>'));
    }

    public function test_blocked_bot_breaks_only_telegram(): void
    {
        $this->configure();
        $this->fakeTelegram(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
        $user = $this->client();
        $this->profile($user, ['language' => 'pt'], ['nginx']);
        $slack = $this->slackChannel($user);
        $telegram = $this->telegramChannel($user);

        $match = $this->alertFor($user);

        $this->assertSame('sent', $match->fresh()->delivery_status);
        $this->assertSame('broken', $telegram->fresh()->status);
        $this->assertStringContainsString('bloqueada no Telegram', $telegram->fresh()->last_error);
        $this->assertSame('active', $slack->fresh()->status);

        $this->actingAs($user)->get(route('alerts.news'))
            ->assertSee('O Telegram recusou o último envio.')
            ->assertSee('Os outros canais continuam recebendo.');
    }

    public function test_group_upgraded_to_supergroup_gets_the_new_id(): void
    {
        $this->configure();
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: group chat was upgraded to a supergroup chat', 'parameters' => ['migrate_to_chat_id' => -1009999999999]], 400)
            ->push(['ok' => true, 'result' => ['message_id' => 2]])]);
        $user = $this->client();
        $channel = $this->telegramChannel($user, ['method' => 'group', 'telegram_chat_id' => '-4001', 'telegram_chat_title' => 'SOC']);

        $this->actingAs($user)->post(route('alerts.telegram.test'))->assertSessionHas('status');

        $this->assertSame('-1009999999999', $channel->fresh()->telegram_chat_id);
        Http::assertSent($this->sentTo('-1009999999999'));
        $this->assertSame('sent', NotificationDelivery::query()->sole()->status);
    }

    public function test_bot_removed_from_group_and_added_back(): void
    {
        $this->configure();
        $this->fakeTelegram();
        $user = $this->client();
        $channel = $this->telegramChannel($user, ['method' => 'group', 'telegram_chat_id' => self::GROUP, 'telegram_chat_title' => 'SOC']);
        $membership = fn (string $status) => ['update_id' => 1, 'my_chat_member' => [
            'chat' => $this->groupChat(),
            'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'],
            'old_chat_member' => ['status' => 'member'],
            'new_chat_member' => ['status' => $status],
        ]];

        app(UpdateHandler::class)->handle($membership('kicked'));
        $this->assertSame(['broken', 'O bot do Security Monitor foi removido do grupo.'], [$channel->fresh()->status, $channel->fresh()->last_error]);

        app(UpdateHandler::class)->handle($membership('member'));
        $this->assertSame(['active', null], [$channel->fresh()->status, $channel->fresh()->last_error]);
    }

    public function test_digest_reaches_telegram(): void
    {
        $this->configure();
        $this->fakeTelegram();
        Carbon::setTestNow(Carbon::parse('2026-10-20 06:30', 'America/Sao_Paulo'));
        $user = $this->client();
        $this->profile($user, ['language' => 'pt'], ['nginx']);
        $channel = $this->telegramChannel($user, ['realtime' => false]);
        $this->alertFor($user);

        $this->artisan('secmon:alerts-digest', ['--force' => true, '--channel' => $channel->id])->assertSuccessful();

        Http::assertSent(fn (Request $r) => $this->sentTo(self::PRIVATE)($r)
            && str_starts_with($r['text'], '📰 <b>Resumo do Security Monitor</b>')
            && str_contains($r['text'], '<b>Afeta você (1)</b>')
            && mb_strlen($r['text']) <= 4096);
        $this->assertSame('sent', NotificationDelivery::query()->where('kind', 'digest')->value('status'));
    }

    public function test_disconnecting_a_group_makes_the_bot_leave_when_unused(): void
    {
        $this->configure();
        $this->fakeTelegram();
        $first = $this->client();
        $second = $this->client();
        $this->telegramChannel($first, ['method' => 'group', 'telegram_chat_id' => self::GROUP]);
        $this->telegramChannel($second, ['method' => 'group', 'telegram_chat_id' => self::GROUP]);

        $this->actingAs($first)->delete(route('alerts.telegram.destroy'))
            ->assertSessionHas('status', 'Telegram desconectado. Os alertas continuam guardados em Alertas.');
        Http::assertNothingSent();

        $this->actingAs($second)->delete(route('alerts.telegram.destroy'));
        Http::assertSent($this->sentTo(self::GROUP, 'leaveChat'));
    }

    public function test_updates_command_handles_and_remembers_the_offset(): void
    {
        $this->configure();
        $user = $this->client();
        $code = $this->startLink($user);
        Http::fake([
            self::API.'/getUpdates' => Http::response(['ok' => true, 'result' => [
                ['update_id' => 500] + $this->message('/start '.$code, $this->privateChat()),
            ]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $this->artisan('secmon:telegram-updates', ['--once' => true])->assertSuccessful();

        $this->assertNotNull($user->telegramChannel()->first());
        $this->assertSame(501, Cache::get(TelegramUpdatesCommand::OFFSET));
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/getUpdates' && $r['timeout'] === 0 && $r['allowed_updates'] === ['message', 'my_chat_member']);
    }

    public function test_notifiers_page_lists_telegram(): void
    {
        $user = $this->client();
        $this->telegramChannel($user, ['method' => 'group', 'telegram_chat_id' => self::GROUP, 'telegram_chat_title' => 'SOC da Acme']);

        $this->actingAs($user)->get(route('alerts.notifiers'))
            ->assertSeeInOrder(['Telegram', 'conectado', 'Chegando em grupo SOC da Acme']);
    }
}
