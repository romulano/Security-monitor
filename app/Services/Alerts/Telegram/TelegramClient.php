<?php

namespace App\Services\Alerts\Telegram;

use App\Models\NotificationChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Tudo o que o Security Monitor fala com a API de bots do Telegram: a entrega dos
 * alertas, as respostas ao /start de quem conecta e a busca das mensagens
 * novas do bot (long polling). O token vai na URL, então nenhum erro daqui
 * repassa a mensagem da exceção HTTP.
 */
class TelegramClient
{
    public const API = 'https://api.telegram.org';

    /** O que o bot pede ao Telegram: mensagens (o /start) e entrada/saída dele das conversas. */
    public const UPDATES = ['message', 'my_chat_member'];

    public static function configured(): bool
    {
        return filled(config('secmon.alerts.telegram.bot_token')) && self::username() !== '';
    }

    /** Usuário do bot, sem o @. */
    public static function username(): string
    {
        return ltrim((string) config('secmon.alerts.telegram.bot_username'), '@');
    }

    /** Abre o Telegram já com o /start do código: na conversa com o bot ou escolhendo um grupo. */
    public static function deepLink(string $code, bool $group = false): string
    {
        return 'https://t.me/'.self::username().'?'.($group ? 'startgroup' : 'start').'='.$code;
    }

    public function post(NotificationChannel $channel, array $payload): TelegramResult
    {
        if (! self::configured()) {
            return TelegramResult::permanent(0, 'not_configured', breaksChannel: false);
        }

        if (! $channel->telegram_chat_id) {
            return TelegramResult::permanent(0, 'no_target', breaksChannel: true);
        }

        $result = $this->send($channel->telegram_chat_id, $payload);

        // Grupo que virou supergrupo ganha outro ID: o Telegram informa o novo e a mensagem vai de novo.
        if ($result->error === 'migrated' && is_scalar($result->data)) {
            $channel->forceFill(['telegram_chat_id' => (string) $result->data])->save();
            $result = $this->send((string) $result->data, $payload);
        }

        return $result;
    }

    public function send(string $chatId, array $payload): TelegramResult
    {
        if (! isset($payload['photo'])) {
            return $this->call('sendMessage', ['chat_id' => $chatId] + $payload);
        }

        $result = $this->call('sendPhoto', ['chat_id' => $chatId] + $payload);

        // Foto que o Telegram não conseguiu buscar (link fora do ar): o aviso sai sem ela.
        if (! $result->ok && $result->status === 400 && ! $result->breaksChannel) {
            $text = ['text' => $payload['caption'] ?? '', 'link_preview_options' => ['is_disabled' => true]] + array_diff_key($payload, array_flip(['photo', 'caption']));
            $result = $this->call('sendMessage', ['chat_id' => $chatId] + $text);
        }

        return $result;
    }

    /** O bot sai do grupo quando nenhum cliente recebe mais alertas nele (conversa privada não tem como sair). */
    public function leaveIfUnused(?string $chatId): void
    {
        if (! $chatId || ! str_starts_with($chatId, '-')
            || NotificationChannel::query()->where('type', 'telegram')->where('telegram_chat_id', $chatId)->exists()) {
            return;
        }

        $this->call('leaveChat', ['chat_id' => $chatId]);
    }

    /**
     * Mensagens novas do bot a partir de `$offset`, esperando até `$wait`
     * segundos por alguma. Nulo quando o Telegram não respondeu.
     */
    public function updates(?int $offset, int $wait): ?array
    {
        $result = $this->call('getUpdates', array_filter([
            'offset' => $offset,
            'timeout' => $wait,
            'allowed_updates' => self::UPDATES,
        ], fn ($v) => $v !== null), timeout: $wait + 10);

        return $result->ok && is_array($result->data) ? $result->data : null;
    }

    public function call(string $method, array $params = [], ?int $timeout = null): TelegramResult
    {
        try {
            $response = Http::timeout($timeout ?? (int) config('secmon.alerts.telegram.timeout', 10))->connectTimeout(5)->asJson()
                ->post(self::API.'/bot'.config('secmon.alerts.telegram.bot_token').'/'.$method, $params);
        } catch (ConnectionException) {
            return TelegramResult::transient(0, 'connection');
        }

        return $this->classify($response);
    }

    private function classify(Response $response): TelegramResult
    {
        $status = $response->status();

        if ($response->successful() && $response->json('ok') === true) {
            return TelegramResult::ok($status, $response->json('result'));
        }

        if ($status === 429) {
            return TelegramResult::rateLimited($status, (int) ($response->json('parameters.retry_after') ?: 5));
        }

        if ($to = $response->json('parameters.migrate_to_chat_id')) {
            return TelegramResult::migrated($status, (string) $to);
        }

        $description = mb_strtolower((string) $response->json('description'));

        // 401 e 404 são do token do Security Monitor, não do cliente.
        if (in_array($status, [401, 404], true)) {
            return TelegramResult::permanent($status, 'unauthorized', breaksChannel: false);
        }

        if ($status === 403) {
            return TelegramResult::permanent($status, match (true) {
                str_contains($description, 'blocked') => 'blocked',
                str_contains($description, 'deactivated') => 'deactivated',
                str_contains($description, 'kicked'), str_contains($description, 'not a member') => 'kicked',
                default => 'forbidden',
            }, breaksChannel: true);
        }

        if ($status === 400 && str_contains($description, 'chat not found')) {
            return TelegramResult::permanent($status, 'chat_not_found', breaksChannel: true);
        }

        if ($status === 400 && preg_match('/not enough rights|have no rights|chat_write_forbidden/', $description)) {
            return TelegramResult::permanent($status, 'no_rights', breaksChannel: true);
        }

        if ($status >= 400 && $status < 500) {
            return TelegramResult::permanent($status, Str::limit($description ?: 'http_'.$status, 120), breaksChannel: false);
        }

        return TelegramResult::transient($status, 'http_'.$status);
    }
}
