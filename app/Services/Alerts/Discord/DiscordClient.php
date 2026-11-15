<?php

namespace App\Services\Alerts\Discord;

use App\Models\NotificationChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Tudo o que o Security Monitor fala com o Discord: o OAuth de quem conecta, a lista de
 * canais do servidor, a abertura da mensagem direta e a entrega dos alertas
 * (pelo bot ou por um webhook colado). Só fala com discord.com: o webhook vem
 * do cliente, então outro host seria um SSRF esperando acontecer.
 */
class DiscordClient
{
    public const API = 'https://discord.com/api/v10';

    /** Ver canais (1024) + Enviar mensagens (2048) + Inserir links (16384). */
    public const PERMISSIONS = 19456;

    private const WEBHOOK = '#^https://(?:ptb\.|canary\.)?discord(?:app)?\.com/api/webhooks/[0-9]{15,22}/[A-Za-z0-9_-]{30,100}$#';

    /** Códigos de erro do Discord que significam "esse destino não recebe mais nada". */
    private const GONE = [10003, 10004, 10015, 50001, 50007, 50013, 50278];

    /** Canais onde o bot pode escrever: texto e anúncios. */
    private const TEXT_TYPES = [0, 5];

    public static function homeGuild(): ?string
    {
        return filled(config('secmon.alerts.discord.home_guild_id')) ? (string) config('secmon.alerts.discord.home_guild_id') : null;
    }

    public static function isWebhookUrl(?string $url): bool
    {
        return is_string($url) && preg_match(self::WEBHOOK, $url) === 1;
    }

    /** Bot e OAuth configurados no .env: sem isso só o webhook colado funciona. */
    public static function configured(): bool
    {
        return filled(config('secmon.alerts.discord.client_id'))
            && filled(config('secmon.alerts.discord.client_secret'))
            && filled(config('secmon.alerts.discord.bot_token'));
    }

    /**
     * `channel`: o cliente escolhe o servidor e o bot entra com 3 permissões.
     * `dm`: o cliente autoriza com a própria conta, só para sabermos quem é.
     * `dm_join`: igual, mais a permissão de colocá-lo na comunidade do Security Monitor
     * (`guilds.join`), oferecida a quem não divide nenhum servidor com o bot:
     * sem servidor em comum, o Discord recusa a mensagem direta (erro 50278).
     */
    public function authorizeUrl(string $mode, string $state, string $redirect): string
    {
        $params = match ($mode) {
            'dm' => ['scope' => 'identify'],
            'dm_join' => ['scope' => 'identify guilds.join'],
            default => ['scope' => 'bot identify', 'permissions' => self::PERMISSIONS, 'integration_type' => 0],
        };

        return 'https://discord.com/oauth2/authorize?'.http_build_query($params + [
            'client_id' => config('secmon.alerts.discord.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $redirect,
            'state' => $state,
            'prompt' => 'consent',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Troca o `code` do retorno pelo token. Com o escopo `bot`, a resposta traz o servidor escolhido. */
    public function exchangeCode(string $code, string $redirect): ?array
    {
        try {
            $response = Http::asForm()->timeout($this->timeout())->connectTimeout(5)
                ->withBasicAuth((string) config('secmon.alerts.discord.client_id'), (string) config('secmon.alerts.discord.client_secret'))
                ->post(self::API.'/oauth2/token', [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirect,
                ]);
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() && $response->json('access_token') ? $response->json() : null;
    }

    /** Quem autorizou: id e nome. */
    public function currentUser(string $accessToken): ?array
    {
        try {
            $response = Http::withToken($accessToken)->timeout($this->timeout())->connectTimeout(5)->get(self::API.'/users/@me');
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() && $response->json('id') ? $response->json() : null;
    }

    /** O token da conta do cliente só serve para saber quem ele é: revogado logo depois. */
    public function revoke(string $accessToken): void
    {
        try {
            Http::asForm()->timeout(5)
                ->withBasicAuth((string) config('secmon.alerts.discord.client_id'), (string) config('secmon.alerts.discord.client_secret'))
                ->post(self::API.'/oauth2/token/revoke', ['token' => $accessToken, 'token_type_hint' => 'access_token']);
        } catch (\Throwable) {
            // melhor esforço
        }
    }

    /**
     * Canais de texto do servidor, na ordem do Discord. Nulo quando o bot
     * não está (mais) no servidor ou o Discord não respondeu.
     *
     * @return list<array{id: string, name: string}>|null
     */
    public function textChannels(string $guildId): ?array
    {
        try {
            $response = $this->bot()->get(self::API."/guilds/{$guildId}/channels");
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return null;
        }

        return collect($response->json())
            ->filter(fn ($c) => in_array($c['type'] ?? null, self::TEXT_TYPES, true))
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->map(fn ($c) => ['id' => (string) $c['id'], 'name' => (string) $c['name']])
            ->values()
            ->all();
    }

    /** Coloca o usuário no servidor (201) ou confirma que já está lá (204), com o token da conta dele. */
    public function addToGuild(string $guildId, string $userId, string $accessToken): DiscordResult
    {
        try {
            $response = $this->bot()->put(self::API."/guilds/{$guildId}/members/{$userId}", ['access_token' => $accessToken]);
        } catch (ConnectionException) {
            return DiscordResult::transient(0, 'connection');
        }

        return $this->classify($response, viaBot: true);
    }

    /** Abre (ou reaproveita) a conversa do bot com o usuário. */
    public function openDm(string $userId): DiscordResult
    {
        try {
            $response = $this->bot()->post(self::API.'/users/@me/channels', ['recipient_id' => $userId]);
        } catch (ConnectionException) {
            return DiscordResult::transient(0, 'connection');
        }

        return $this->classify($response, viaBot: true);
    }

    /** O bot sai do servidor quando nenhum cliente usa mais aquele servidor. */
    public function leaveGuild(string $guildId): void
    {
        try {
            $this->bot()->delete(self::API."/users/@me/guilds/{$guildId}");
        } catch (\Throwable) {
            // melhor esforço
        }
    }

    public function post(NotificationChannel $channel, array $payload): DiscordResult
    {
        if ($channel->method === 'webhook') {
            $url = (string) $channel->webhook_url;

            if (! self::isWebhookUrl($url)) {
                return DiscordResult::permanent(0, 'invalid_webhook', breaksChannel: true);
            }

            try {
                $response = Http::timeout($this->timeout())->connectTimeout(5)->withoutRedirecting()->asJson()
                    ->post($url.'?wait=true', $payload);
            } catch (ConnectionException) {
                return DiscordResult::transient(0, 'connection');
            }

            return $this->classify($response, viaBot: false);
        }

        if (! filled(config('secmon.alerts.discord.bot_token'))) {
            return DiscordResult::permanent(0, 'not_configured', breaksChannel: false);
        }

        if (! $channel->discord_channel_id) {
            return DiscordResult::permanent(0, 'no_target', breaksChannel: true);
        }

        try {
            $response = $this->bot()->post(self::API."/channels/{$channel->discord_channel_id}/messages", $payload);
        } catch (ConnectionException) {
            return DiscordResult::transient(0, 'connection');
        }

        return $this->classify($response, viaBot: true);
    }

    private function bot(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bot '.config('secmon.alerts.discord.bot_token'),
            'User-Agent' => 'DiscordBot (https://example.com, 1.0)',
        ])->timeout($this->timeout())->connectTimeout(5)->asJson();
    }

    private function classify(Response $response, bool $viaBot): DiscordResult
    {
        $status = $response->status();

        if ($response->successful()) {
            return DiscordResult::ok($status, (array) $response->json());
        }

        if ($status === 429) {
            $wait = $response->json('retry_after') ?? $response->header('Retry-After') ?: 5;

            return DiscordResult::rateLimited($status, (int) ceil((float) $wait));
        }

        $code = (int) $response->json('code');
        $error = $code ? 'discord_'.$code : 'http_'.$status;

        // 401 no bot é problema do token do Security Monitor, não do cliente; no webhook, o webhook foi apagado.
        if ($status === 401) {
            return $viaBot
                ? DiscordResult::permanent($status, 'unauthorized', breaksChannel: false)
                : DiscordResult::permanent($status, 'invalid_webhook_token', breaksChannel: true);
        }

        if (in_array($code, self::GONE, true) || $status === 404) {
            return DiscordResult::permanent($status, $error, breaksChannel: true);
        }

        if ($status >= 400 && $status < 500) {
            return DiscordResult::permanent($status, $error, breaksChannel: false);
        }

        return DiscordResult::transient($status, $error);
    }

    private function timeout(): int
    {
        return (int) config('secmon.alerts.discord.timeout', 10);
    }
}
