<?php

namespace Tests\Feature\Account;

use App\Models\MonitoredDomain;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\PentestDomain;
use App\Models\User;
use App\Services\Auth\Reauthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Confirmar que é você com um código num app: só uma conversa privada (Telegram
 * ou mensagem direta do Discord) que já estava conectada quando a pessoa
 * entrou; 10 minutos, uma vez só, 5 tentativas; o código em claro não fica
 * gravado em lugar nenhum.
 */
class ReauthCodeTest extends TestCase
{
    use RefreshDatabase;

    private const TELEGRAM = 'https://api.telegram.org/bot123456789:token-do-bot';

    private const CHAT = '987654321';

    private const DM = '333333333333333333';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'secmon.alerts.telegram.bot_token' => '123456789:token-do-bot',
            'secmon.alerts.telegram.bot_username' => 'SecurityMonitorDevBot',
            'secmon.alerts.discord.bot_token' => 'token-do-bot',
        ]);
    }

    private function fakeApps(array $extra = []): void
    {
        Http::fake($extra + [
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            'discord.com/api/v10/channels/*/messages' => Http::response(['id' => '777777777777777777']),
        ]);
    }

    private function channel(User $user, array $attributes = []): NotificationChannel
    {
        return NotificationChannel::query()->create($attributes + [
            'user_id' => $user->id,
            'type' => 'telegram',
            'method' => 'private',
            'telegram_chat_id' => self::CHAT,
            'telegram_username' => '@romulo',
            'status' => 'active',
            'digest_days' => [1, 2, 3, 4, 5],
        ]);
    }

    private function discordDm(User $user): NotificationChannel
    {
        return $this->channel($user, [
            'type' => 'discord', 'method' => 'dm', 'telegram_chat_id' => null, 'telegram_username' => null,
            'discord_channel_id' => self::DM, 'discord_user_id' => '222222222222222222', 'discord_username' => 'romulo',
        ]);
    }

    /** Entra pela tela de login, como a pessoa: é aí que os apps privados da conta ficam guardados. */
    private function login(User $user): void
    {
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('account.show'));
    }

    private function send(NotificationChannel $channel)
    {
        return $this->from(route('account.show'))->post(route('account.reauth.code'), ['channel' => $channel->id]);
    }

    private function confirm(string $code)
    {
        return $this->from(route('account.show'))->post(route('account.reauth.code.confirm'), ['reauth_code' => $code]);
    }

    /** O código da última mensagem que o Telegram recebeu. */
    private function telegramCode(): string
    {
        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->last(fn (Request $r) => $r->url() === self::TELEGRAM.'/sendMessage');
        $this->assertNotNull($sent, 'nenhuma mensagem foi para o Telegram');
        $this->assertSame(self::CHAT, (string) $sent['chat_id']);
        $this->assertMatchesRegularExpression('/<code>(\d{6})<\/code>/', $sent['text']);
        preg_match('/<code>(\d{6})<\/code>/', $sent['text'], $match);

        return $match[1];
    }

    private function confirmed(User $user): ?string
    {
        return Reauthentication::method($user, session()->driver());
    }

    private function codeError(): string
    {
        return (string) session('errors')?->getBag('reauth')->first('reauth_code');
    }

    public function test_the_code_goes_to_the_private_conversation_and_confirms(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $this->login($user);

        $this->get(route('account.show'))->assertSee('Receber um código no Telegram')->assertSee('conversa privada com @romulo');

        $this->send($channel)->assertRedirect(route('account.show'))
            ->assertSessionHas('status', 'Mandamos o código para o Telegram (conversa privada com @romulo). Ele vale por 10 minutos.');
        $this->get(route('account.show'))->assertSee('Código que chegou no Telegram');

        $this->confirm($this->telegramCode())->assertRedirect(route('account.show'))->assertSessionHasNoErrors();

        $this->assertSame('code', $this->confirmed($user));
        $this->get(route('account.show'))->assertSee('Você confirmou que é você com um código.')->assertDontSee('Receber um código');
    }

    public function test_a_code_after_the_password_does_not_downgrade_the_confirmation(): void
    {
        // Nota do Red Team na P4c: confirmar um código genérico depois da senha trocava a confirmação "com a senha" (vale
        // para tudo) pela "com um código" (vale só para as ações da conta), e o aceite de um teste pedia a senha de novo.
        $this->fakeApps();
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $this->login($user);
        app(Reauthentication::class)->confirmPassword($user, 'password', session()->driver());
        $this->travel(3)->minutes();

        $this->send($channel)->assertSessionHasNoErrors();
        $this->confirm($this->telegramCode())->assertSessionHasNoErrors();

        $this->assertSame('password', $this->confirmed($user));
        $this->assertSame('password', Reauthentication::method($user, session()->driver(), 'empresa.com.br'));

        // A senha vale só até o prazo dela: o código não a estende.
        $this->travel(8)->minutes();
        $this->assertNull($this->confirmed($user));
    }

    public function test_a_direct_message_on_discord_also_receives_it(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $channel = $this->discordDm($user);
        $this->login($user);

        $this->send($channel)->assertSessionHas('status');

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->last(fn (Request $r) => str_contains($r->url(), '/channels/'.self::DM.'/messages'));
        preg_match('/`(\d{6})`/', (string) $sent['content'], $match);
        $this->assertSame([], $sent['allowed_mentions']['parse']);

        $this->confirm($match[1])->assertSessionHasNoErrors();
        $this->assertSame('code', $this->confirmed($user));
    }

    public function test_the_code_in_clear_is_not_kept_anywhere(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $this->login($user);

        $this->send($channel);
        $code = $this->telegramCode();

        // O histórico do app (o do cliente e o do admin): a mesma mensagem, com pontos no lugar do código.
        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame(['code', 'sent', 'Código de confirmação (vale 10 minutos)'], [$delivery->kind, $delivery->status, $delivery->summary]);
        $this->assertStringContainsString('<code>••••••</code>', $delivery->payload['text']);
        $this->assertStringNotContainsString($code, $delivery->payload['text']);

        // O cache: só o HMAC, e o escopo do código (o domínio do aceite; nulo nas ações da conta).
        $entry = Cache::get('reauth-code:'.$user->id);
        $this->assertSame(['hash', 'nonce', 'owner', 'app', 'scope', 'expires'], array_keys($entry));
        $this->assertNull($entry['scope']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry['hash']);
        $this->assertNotContains($code, $entry);

        // A sessão: nem o aviso nem o old() levam o código.
        $this->assertStringNotContainsString($code, (string) session('status'));
        $this->assertNull(session()->getOldInput('reauth_code'));
    }

    public function test_only_a_private_conversation_receives_it(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $group = $this->channel($user, ['method' => 'group', 'telegram_chat_id' => '-1001234567890', 'telegram_chat_title' => 'SOC da Acme']);
        $discord = $this->channel($user, ['type' => 'discord', 'method' => 'channel', 'telegram_chat_id' => null, 'discord_channel_id' => '444444444444444444']);
        $slack = $this->channel($user, ['type' => 'slack', 'method' => 'webhook', 'telegram_chat_id' => null, 'webhook_url' => 'https://hooks.slack.com/services/T/B/x']);
        $this->login($user);

        $this->get(route('account.show'))->assertDontSee('Receber um código');

        foreach ([$group, $discord, $slack] as $channel) {
            $this->send($channel)->assertSessionHas('error');
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_a_conversation_connected_after_the_login_does_not_receive_it(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $this->login($user);

        // Quem só tem a sessão não pode conectar o próprio Telegram e receber o código ali.
        $later = $this->channel($user);

        $this->get(route('account.show'))->assertDontSee('Receber um código');
        $this->send($later)->assertSessionHas('error');

        // No próximo login, ele passa a valer.
        $this->post(route('logout'));
        $this->login($user);
        $this->send($later)->assertSessionHas('status');
    }

    public function test_a_conversation_moved_to_another_chat_after_the_login_does_not_receive_it(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $this->login($user);

        $channel->update(['telegram_chat_id' => '111111111']);

        $this->send($channel)->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_a_disconnected_conversation_or_one_of_another_account_does_not_receive_it(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $other = $this->channel(User::factory()->create(), ['telegram_chat_id' => '555555555']);
        $this->login($user);

        $this->send($other)->assertNotFound();

        $channel->update(['status' => 'broken']);
        $this->send($channel)->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_a_channel_that_is_not_a_number_is_refused_by_the_validation(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $this->channel($user);
        $this->login($user);

        // Antes de procurar o app no banco: o Postgres recusaria o texto num id inteiro (erro 500).
        foreach (['abc', '1 or 1=1', ['1']] as $channel) {
            $this->from(route('account.show'))->post(route('account.reauth.code'), ['channel' => $channel])
                ->assertRedirect(route('account.show'))
                ->assertSessionHasErrors(['channel' => 'Escolha o app que recebe o código.']);
        }

        Http::assertNothingSent();
    }

    public function test_entering_by_the_remember_cookie_does_not_count(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $user->forceFill(['remember_token' => 'lembrar-123'])->save();
        $channel = $this->channel($user);

        $this->withCookie(Auth::guard()->getRecallerName(), $user->id.'|lembrar-123|'.$user->getAuthPassword());
        $this->get(route('account.show'))->assertOk()->assertDontSee('Receber um código');
        $this->assertAuthenticatedAs($user);

        $this->send($channel)->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_five_wrong_codes_burn_it(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $this->login($user);
        $this->send($channel);
        $code = $this->telegramCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach ([4, 3, 2] as $left) {
            $this->confirm($wrong)->assertSessionHasErrorsIn('reauth', ['reauth_code' => "O código não confere. Restam {$left} tentativas."]);
        }
        $this->confirm($wrong)->assertSessionHasErrorsIn('reauth', ['reauth_code' => 'O código não confere. Resta 1 tentativa.']);
        $this->confirm($wrong)->assertSessionHasErrorsIn('reauth', ['reauth_code' => 'O código não confere. Foram 5 tentativas: peça outro.']);

        $this->confirm($code)->assertSessionHasErrorsIn('reauth', ['reauth_code' => 'O código venceu ou não foi pedido aqui. Peça outro.']);
        $this->assertNull($this->confirmed($user));
    }

    public function test_the_code_works_once_even_with_two_at_the_same_time(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $this->login($user);

        $this->send($channel);
        $code = $this->telegramCode();
        $this->confirm($code)->assertSessionHasNoErrors();
        $this->confirm($code)->assertSessionHasErrorsIn('reauth', 'reauth_code');

        // A outra conferência, que leu o código antes de ele sair do cache, perde no `add`.
        $this->send($channel);
        $code = $this->telegramCode();
        Cache::add('reauth-code-used:'.Cache::get('reauth-code:'.$user->id)['nonce'], true, 600);
        $this->confirm($code);
        $this->assertSame('Este código já foi usado. Peça outro.', $this->codeError());
    }

    public function test_the_code_lasts_ten_minutes(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $this->login($user);
        $this->send($channel);
        $code = $this->telegramCode();

        $this->travel(10)->minutes();

        $this->confirm($code);
        $this->assertSame('O código venceu ou não foi pedido aqui. Peça outro.', $this->codeError());
        $this->assertNull($this->confirmed($user));
    }

    public function test_a_new_code_cancels_the_previous_one(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $this->login($user);

        $this->send($channel);
        $first = $this->telegramCode();
        $nonce = Cache::get('reauth-code:'.$user->id)['nonce'];
        $this->send($channel);
        $second = $this->telegramCode();

        $this->assertNotSame($nonce, Cache::get('reauth-code:'.$user->id)['nonce']);

        if ($first !== $second) {
            $this->confirm($first)->assertSessionHasErrorsIn('reauth', 'reauth_code');
        }

        $this->confirm($second)->assertSessionHasNoErrors();
        $this->assertSame('code', $this->confirmed($user));
    }

    public function test_the_code_only_counts_in_the_session_that_asked_for_it(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $this->login($user);
        $this->send($channel);
        $code = $this->telegramCode();

        // Outra sessão da mesma conta não tem a chave que esta guardou ao pedir.
        session()->forget('reauth_code');

        $this->confirm($code);
        $this->assertSame('O código venceu ou não foi pedido aqui. Peça outro.', $this->codeError());
    }

    public function test_three_codes_every_ten_minutes(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $this->login($user);

        for ($i = 0; $i < 3; $i++) {
            $this->send($channel)->assertSessionHas('status');
        }

        $this->send($channel)->assertSessionHas('error', 'Muitos códigos pedidos. Tente de novo em 10 min, ou confirme com a senha.');
        $this->assertSame(3, NotificationDelivery::query()->count());
    }

    public function test_ten_codes_a_day(): void
    {
        $this->fakeApps();
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $this->login($user);

        for ($i = 1; $i <= 10; $i++) {
            $this->send($channel)->assertSessionHas('status');

            if ($i % 3 === 0) {
                $this->travel(10)->minutes();
            }
        }

        // Com quem roubou a sessão pedindo o dia todo: 50 tentativas por dia, num milhão de códigos.
        $this->travel(10)->minutes();
        $this->send($channel)->assertSessionHas('error', 'Muitos códigos pedidos hoje. Tente de novo em 24 h, ou confirme com a senha.');
        $this->assertSame(10, NotificationDelivery::query()->count());

        $this->travel(1)->days();
        $this->send($channel)->assertSessionHas('status');
    }

    public function test_a_send_the_app_refuses_says_why_and_leaves_no_code(): void
    {
        $this->fakeApps(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $this->login($user);

        $this->send($channel)->assertSessionHas('error', 'O código não foi enviado. A conversa com o bot do Security Monitor foi bloqueada no Telegram. Desbloqueie o bot ou conecte de novo.');

        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame(['code', 'failed'], [$delivery->kind, $delivery->status]);
        $this->assertStringContainsString('••••••', $delivery->payload['text']);
        $this->assertSame('broken', $channel->refresh()->status);
        $this->assertNull(Cache::get('reauth-code:'.$user->id));
    }

    public function test_the_admin_cannot_resend_a_code(): void
    {
        $this->fakeApps(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 500, 'description' => 'Internal'], 500)]);
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $this->login($user);
        $this->send($channel);
        $delivery = NotificationDelivery::query()->sole();
        Auth::logout();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.alerts.deliveries'))->assertOk()->assertDontSee(route('admin.alerts.deliveries.retry', $delivery));
        $this->actingAs($admin)->post(route('admin.alerts.deliveries.retry', $delivery))
            ->assertSessionHas('error', 'Código de confirmação não é reenviado: quem pediu pede outro.');

        $this->assertSame('failed', $delivery->refresh()->status);
    }

    /** Uma conta que pode autorizar um teste de segurança: os testes no ar (PENTEST_ALLOWED) e a liberação do admin (L1 do Red Team). */
    private function grantedAccount(): User
    {
        config(['secmon.pentest.allowed' => true]);
        $user = User::factory()->create();
        $user->forceFill(['pentest_access_granted_at' => now()])->save();

        return $user;
    }

    /** Domínio inscrito nos testes de segurança, no passo `$step`, com a posse comprovada ou não. */
    private function pentestDomain(User $user, string $host, int $step = 7, bool $proven = true): PentestDomain
    {
        $copy = MonitoredDomain::query()->create(['user_id' => $user->id, 'domain' => $host, 'verify_token' => 'abc123', 'verified_at' => $proven ? now() : null]);

        return PentestDomain::query()->create(['user_id' => $user->id, 'domain_id' => $copy->id, 'host' => $host, 'step_reached' => $step]);
    }

    public function test_asked_for_a_security_test_the_code_names_the_action_and_the_domain(): void
    {
        // Q6 do Blue Team: o código do aceite de um teste de segurança diz o que ele confirma e onde.
        // São 8 pedidos seguidos: o limite de requisições (6 por minuto) não é o assunto aqui.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->fakeApps();
        $user = $this->grantedAccount();
        $channel = $this->channel($user);
        $this->pentestDomain($user, 'empresa.com.br');
        $this->pentestDomain(User::factory()->create(), 'outra.com.br');
        $this->login($user);

        // Só um domínio desta conta que pode ser autorizado agora (A2 do Red Team): o de outra conta, um nome
        // solto ou malformado, um digitado sem a posse e um que não chegou ao passo 7 dão 404, e nada sai.
        $this->pentestDomain($user, 'sem-posse.com.br', proven: false);
        $this->pentestDomain($user, 'no-passo-2.com.br', step: 2);
        PentestDomain::query()->create(['user_id' => $user->id, 'domain_id' => MonitoredDomain::query()->create(['user_id' => $user->id, 'domain' => 'sumiu.com.br', 'verify_token' => 'x', 'verified_at' => now()])->id,
            'host' => 'sumiu.com.br', 'step_reached' => 7, 'ownership_status' => 'missing']);
        foreach (['outra.com.br', 'nao-inscrito.com.br', "1' OR '1'='1", 'EMPRESA.COM.BR', 'sem-posse.com.br', 'no-passo-2.com.br', 'sumiu.com.br'] as $name) {
            $this->from(route('account.show'))->post(route('account.reauth.code'), ['channel' => $channel->id, 'dominio' => $name])->assertNotFound();
        }
        Http::assertNothingSent();

        $this->from(route('account.show'))->post(route('account.reauth.code'), ['channel' => $channel->id, 'dominio' => 'empresa.com.br'])->assertSessionHas('status');
        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->last(fn (Request $r) => $r->url() === self::TELEGRAM.'/sendMessage');
        // O texto final do Blue Team (rodada 3, item 1). O domínio só aparece dentro de <code>, nunca em texto
        // corrido, onde o Telegram o transformaria em link.
        $this->assertStringStartsWith('🔐 <b>Código para confirmar uma ação de teste de segurança no Security Monitor.</b> Domínio: <code>empresa.com.br</code>. Código: <code>', $sent['text']);
        $this->assertSame(1, substr_count($sent['text'], 'empresa.com.br'));
        $this->assertStringEndsWith('Vale por 10 minutos e uma vez só, na página do Security Monitor que pediu. Não passe este código a ninguém: o Security Monitor nunca pede o código por mensagem. Se não foi você que pediu, troque a sua senha.', $sent['text']);
        $this->assertStringContainsString('Domínio: <code>empresa.com.br</code>. Código: <code>••••••</code>', NotificationDelivery::query()->sole()->payload['text']);

        // O código confirma só o que anunciou: o aceite em empresa.com.br, e nada da conta (A1 do Red Team).
        $this->confirm($this->telegramCode())->assertSessionHasNoErrors();
        $this->assertSame('code', Reauthentication::method($user, session()->driver(), 'empresa.com.br'));
        $this->assertNull(Reauthentication::method($user, session()->driver(), 'outra.com.br'));
        $this->assertNull($this->confirmed($user));

        // Sem o domínio, o texto de sempre, sem nome nenhum.
        $this->from(route('account.show'))->post(route('account.reauth.code'), ['channel' => $channel->id])->assertSessionHas('status');
        $generic = collect(Http::recorded())->map(fn ($pair) => $pair[0])->last(fn (Request $r) => $r->url() === self::TELEGRAM.'/sendMessage')['text'];
        $this->assertMatchesRegularExpression('/^🔐 <b>Código de confirmação do Security Monitor:<\/b> <code>\d{6}<\/code>\n\nVale por 10 minutos e uma vez só, na página do Security Monitor que pediu\. '
            .'Não passe este código a ninguém: o Security Monitor nunca pede o código por mensagem\. Se não foi você que pediu, troque a sua senha\.$/u', $generic);
        $this->assertStringNotContainsString('.com.br', $generic);
    }

    public function test_a_code_for_a_security_test_does_not_change_the_account(): void
    {
        $this->fakeApps();
        $user = $this->grantedAccount();
        $channel = $this->channel($user);
        $this->pentestDomain($user, 'empresa.com.br');
        $this->login($user);

        $this->from(route('account.show'))->post(route('account.reauth.code'), ['channel' => $channel->id, 'dominio' => 'empresa.com.br']);
        $this->confirm($this->telegramCode())->assertSessionHasNoErrors();

        // A mensagem anunciou uma ação de teste de segurança: trocar o e-mail continua pedindo a senha.
        $this->from(route('account.show'))->put(route('account.update'), ['name' => $user->name, 'email' => 'novo@empresa.com.br'])
            ->assertSessionHasErrorsIn('profile', ['current_password' => 'Para trocar o e-mail, digite a sua senha atual.']);
        $this->assertNotSame('novo@empresa.com.br', $user->refresh()->email);
        $this->get(route('account.show'))->assertDontSee('Você confirmou que é você com um código.');
    }

    public function test_on_discord_the_code_also_names_the_domain(): void
    {
        $this->fakeApps();
        $user = $this->grantedAccount();
        $channel = $this->discordDm($user);
        $this->pentestDomain($user, 'empresa.com.br');
        $this->login($user);

        $this->from(route('account.show'))->post(route('account.reauth.code'), ['channel' => $channel->id, 'dominio' => 'empresa.com.br'])->assertSessionHas('status');

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->last(fn (Request $r) => str_contains($r->url(), '/channels/'.self::DM.'/messages'));
        $this->assertMatchesRegularExpression('/^🔐 \*\*Código para confirmar uma ação de teste de segurança no Security Monitor\.\*\* Domínio: `empresa\.com\.br`\. Código: `\d{6}`$/u', $sent['content']);
        $this->assertSame(1, substr_count($sent['content'], 'empresa.com.br'));
        $this->assertSame("Vale por 10 minutos e uma vez só, na página do Security Monitor que pediu.\nNão passe este código a ninguém: o Security Monitor nunca pede o código por mensagem. Se não foi você que pediu, troque a sua senha.",
            $sent['embeds'][0]['description']);

        // Sem o domínio, o texto de sempre.
        $this->from(route('account.show'))->post(route('account.reauth.code'), ['channel' => $channel->id])->assertSessionHas('status');
        $generic = collect(Http::recorded())->map(fn ($pair) => $pair[0])->last(fn (Request $r) => str_contains($r->url(), '/channels/'.self::DM.'/messages'));
        $this->assertMatchesRegularExpression('/^🔐 \*\*Código de confirmação do Security Monitor:\*\* `\d{6}`$/u', $generic['content']);
    }
}
