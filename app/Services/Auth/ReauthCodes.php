<?php

namespace App\Services\Auth;

use App\Exceptions\ActionRefused;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Alerts\Discord\DiscordClient;
use App\Services\Alerts\Discord\DiscordResult;
use App\Services\Alerts\Discord\MessageBuilder as DiscordMessages;
use App\Services\Alerts\Telegram\MessageBuilder as TelegramMessages;
use App\Services\Alerts\Telegram\TelegramClient;
use App\Services\Alerts\Telegram\TelegramResult;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Confirmar que é você com um código de 6 dígitos mandado a um app conectado
 * (reautenticação). O código só vai para uma conversa privada do Telegram ou
 * uma mensagem direta do Discord, porque num grupo ou canal outras pessoas
 * leriam. E só para uma que já estava conectada, no mesmo destino, quando a
 * pessoa entrou com a senha ou com o GitHub: com a sessão sozinha, quem a
 * roubou conectaria o próprio Telegram e receberia o código ali. Entrar pelo
 * "continuar conectado" (o cookie) não conta como entrada.
 *
 * O código vale 10 minutos, uma vez só, por 5 tentativas e só na sessão que o
 * pediu; pedir outro anula o anterior. Pedido no aceite de um teste de
 * segurança, a mensagem diz a ação e o domínio (Q6 do Blue Team). O cache guarda só o hash (HMAC com a
 * APP_KEY). O envio sai na hora, sem fila, e fica no histórico do app (`code`)
 * com pontos no lugar do código: o código em claro não é gravado em lugar nenhum.
 */
class ReauthCodes
{
    public const MINUTES = 10;

    public const ATTEMPTS = 5;

    /** Códigos que uma conta pode pedir a cada 10 minutos. */
    public const SENDS = 3;

    /**
     * E por dia: com a sessão roubada, quem pede código o dia todo tenta no
     * máximo 50 (5 por código) num milhão, e o app da pessoa não vira spam.
     */
    public const SENDS_PER_DAY = 10;

    /** O que o histórico de envios mostra no lugar do código. */
    private const HIDDEN = '••••••';

    private const DESTINATIONS = 'reauth_destinations';

    private const OWNER = 'reauth_code';

    public function __construct(
        private TelegramClient $telegram,
        private DiscordClient $discord,
        private TelegramMessages $telegramMessages,
        private DiscordMessages $discordMessages,
    ) {}

    /** No login com a senha ou com o GitHub: guarda os destinos privados que a conta tinha naquela hora. */
    public function rememberDestinations(User $user, Session $session): void
    {
        $session->put(self::DESTINATIONS, [
            'user' => $user->id,
            'channels' => $this->privateChannels($user)->mapWithKeys(fn (NotificationChannel $c) => [$c->id => self::destination($c)])->all(),
        ]);
    }

    /**
     * Os apps que podem receber o código agora: privados, conectados e com o
     * mesmo destino de quando a pessoa entrou.
     *
     * @return Collection<int, NotificationChannel>
     */
    public function channels(User $user, Session $session): Collection
    {
        $known = $session->get(self::DESTINATIONS);

        if (! is_array($known) || (int) ($known['user'] ?? 0) !== $user->id) {
            return collect();
        }

        return $this->privateChannels($user)
            ->filter(fn (NotificationChannel $c) => $c->isActive() && hash_equals((string) ($known['channels'][$c->id] ?? ''), self::destination($c)))
            ->values();
    }

    /**
     * O código que esta sessão pediu para esta ação (`$scope`: nulo nas da conta,
     * o domínio no aceite de um teste) e ainda vale, para a tela.
     *
     * @return array{app: string, until: Carbon}|null
     */
    public function pending(User $user, Session $session, ?string $scope = null): ?array
    {
        $entry = $this->entry($user, $session);

        return $entry && ($entry['scope'] ?? null) === $scope ? ['app' => $entry['app'], 'until' => Carbon::createFromTimestamp($entry['expires'])] : null;
    }

    /**
     * Gera um código e manda ao app escolhido; devolve o app, para o aviso na tela.
     * `$domain`: o domínio do teste de segurança que a confirmação vai autorizar.
     */
    public function send(User $user, int $channelId, Session $session, ?string $domain = null): NotificationChannel
    {
        $channel = $this->channels($user, $session)->firstWhere('id', $channelId);

        if (! $channel) {
            throw new ActionRefused('Esse app não recebe o código. Ele vai só para uma conversa privada do Telegram ou uma mensagem direta do Discord que já estava conectada quando você entrou; um app conectado depois passa a valer no próximo login.');
        }

        $limit = 'reauth-code-send:'.$user->id;
        $daily = 'reauth-code-send-day:'.$user->id;

        if (RateLimiter::tooManyAttempts($daily, self::SENDS_PER_DAY)) {
            throw new ActionRefused('Muitos códigos pedidos hoje. Tente de novo em '.max(1, (int) ceil(RateLimiter::availableIn($daily) / 3600)).' h, ou confirme com a senha.');
        }

        if (RateLimiter::tooManyAttempts($limit, self::SENDS)) {
            throw new ActionRefused('Muitos códigos pedidos. Tente de novo em '.max(1, (int) ceil(RateLimiter::availableIn($limit) / 60)).' min, ou confirme com a senha.');
        }

        RateLimiter::hit($limit, self::MINUTES * 60);
        RateLimiter::hit($daily, 24 * 3600);

        $code = sprintf('%06d', random_int(0, 999_999));
        $result = $channel->isTelegram()
            ? $this->telegram->post($channel, $this->telegramMessages->code($code, $domain))
            : $this->discord->post($channel, $this->discordMessages->code($code, $domain));

        $this->record($channel, $result, $domain);

        if (! $result->ok) {
            throw new ActionRefused('O código não foi enviado. '.$result->humanError());
        }

        // Um código por vez: o novo anula o anterior. A sessão guarda a chave que prova que foi ela que pediu.
        $nonce = Str::random(32);
        $owner = Str::random(40);
        $expires = now()->addMinutes(self::MINUTES);

        Cache::put(self::key($user), [
            'hash' => self::hash($nonce, $code),
            'nonce' => $nonce,
            'owner' => hash('sha256', $owner),
            'app' => $channel->appName(),
            // A ação que a mensagem anunciou: o código só confirma ela (A1 do Red Team).
            'scope' => $domain,
            'expires' => $expires->getTimestamp(),
        ], $expires);
        $session->put(self::OWNER, $owner);

        return $channel;
    }

    /**
     * Confere o código digitado e, se bater, registra a confirmação. A recusa
     * volta com o aviso no campo `reauth_code` (bag `reauth`).
     *
     * @throws ValidationException
     */
    public function confirm(User $user, string $code, Session $session): void
    {
        $entry = $this->entry($user, $session) ?? throw self::refuse('O código venceu ou não foi pedido aqui. Peça outro.');
        $tries = 'reauth-code-try:'.$entry['nonce'];

        if (RateLimiter::tooManyAttempts($tries, self::ATTEMPTS)) {
            Cache::forget(self::key($user));

            throw self::refuse('Foram 5 tentativas com este código. Peça outro.');
        }

        if (! hash_equals($entry['hash'], self::hash($entry['nonce'], $code))) {
            $left = self::ATTEMPTS - RateLimiter::hit($tries, self::MINUTES * 60);

            if ($left <= 0) {
                Cache::forget(self::key($user));

                throw self::refuse('O código não confere. Foram 5 tentativas: peça outro.');
            }

            throw self::refuse('O código não confere. '.($left === 1 ? 'Resta 1 tentativa.' : "Restam {$left} tentativas."));
        }

        // Uma vez só, mesmo com duas conferências ao mesmo tempo: só o primeiro `add` vence.
        if (! Cache::add('reauth-code-used:'.$entry['nonce'], true, now()->addMinutes(self::MINUTES))) {
            throw self::refuse('Este código já foi usado. Peça outro.');
        }

        Cache::forget(self::key($user));
        $session->forget(self::OWNER);
        Reauthentication::mark($user, 'code', $session, $entry['scope'] ?? null);
    }

    /** @return array{hash: string, nonce: string, owner: string, app: string, scope: ?string, expires: int}|null */
    private function entry(User $user, Session $session): ?array
    {
        $entry = Cache::get(self::key($user));
        $owner = $session->get(self::OWNER);

        if (! is_array($entry) || ! is_string($owner) || ! hash_equals((string) ($entry['owner'] ?? ''), hash('sha256', $owner))
            || (int) ($entry['expires'] ?? 0) <= now()->getTimestamp()) {
            return null;
        }

        return $entry;
    }

    /**
     * As conversas privadas da conta que podem receber o código. Conta de empresa: nenhuma (§4.13, E2e) — o código de
     * segurança sai só por e-mail ou pelo app autenticador, nunca por um canal que a empresa ou outra pessoa vê.
     *
     * @return Collection<int, NotificationChannel>
     */
    private function privateChannels(User $user): Collection
    {
        if ($user->companyMembership()->exists()) {
            return new Collection;
        }

        return $user->notificationChannels()->get()->filter(fn (NotificationChannel $c) => $c->isPrivate())->values();
    }

    /** O destino do app (a conversa ou a mensagem direta) como hash: a sessão não guarda o id do chat. */
    private static function destination(NotificationChannel $channel): string
    {
        return hash('sha256', implode('|', [
            $channel->type,
            $channel->method,
            $channel->isTelegram() ? $channel->telegram_chat_id : $channel->discord_channel_id,
        ]));
    }

    private static function hash(string $nonce, string $code): string
    {
        return hash_hmac('sha256', $nonce.'|'.$code, (string) config('app.key'));
    }

    private static function key(User $user): string
    {
        return 'reauth-code:'.$user->id;
    }

    /** O envio fica no histórico do app como qualquer outro, com pontos no lugar do código. */
    private function record(NotificationChannel $channel, TelegramResult|DiscordResult $result, ?string $domain): void
    {
        NotificationDelivery::query()->create([
            'channel_id' => $channel->id,
            'user_id' => $channel->user_id,
            'kind' => 'code',
            'status' => $result->ok ? 'sent' : 'failed',
            'attempts' => 1,
            'response_code' => $result->status ?: null,
            'error' => $result->ok ? null : $result->humanError(),
            'payload' => $channel->isTelegram() ? $this->telegramMessages->code(self::HIDDEN, $domain) : $this->discordMessages->code(self::HIDDEN, $domain),
            'summary' => 'Código de confirmação (vale 10 minutos)',
            'sent_at' => $result->ok ? now() : null,
        ]);

        if ($result->ok) {
            $channel->forceFill(['last_success_at' => now()])->save();
        } elseif ($result->breaksChannel) {
            $channel->forceFill(['status' => 'broken', 'broken_at' => now(), 'last_error' => $result->humanError()])->save();
        }
    }

    private static function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['reauth_code' => $message])->errorBag('reauth');
    }
}
