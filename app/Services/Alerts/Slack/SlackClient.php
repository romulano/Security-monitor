<?php

namespace App\Services\Alerts\Slack;

use App\Models\NotificationChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Posta no Slack pelo Incoming Webhook do canal (o "Adicionar ao Slack" também
 * termina num webhook) ou pelo webhook de um fluxo de trabalho (Workflow
 * Builder), que recebe variáveis de texto em vez de Block Kit, e faz o OAuth
 * do "Adicionar ao Slack" (slack.com/api). Só posta em hooks.slack.com: o
 * endereço vem do cliente, então qualquer outro host seria um SSRF esperando
 * acontecer.
 */
class SlackClient
{
    private const API = 'https://slack.com/api';

    private const WEBHOOK = '#^https://hooks\.slack\.com/services/[A-Za-z0-9]+/[A-Za-z0-9]+/[A-Za-z0-9]+$#';

    private const WORKFLOW = '#^https://hooks\.slack\.com/triggers/[A-Za-z0-9]+/[0-9]+/[A-Za-z0-9]+$#';

    /** Erros do Slack que significam "este canal não recebe mais nada". */
    private const CHANNEL_GONE = [
        'no_service', 'no_active_hooks', 'no_team', 'team_disabled', 'invalid_token',
        'channel_not_found', 'channel_is_archived', 'action_prohibited', 'posting_to_general_channel_denied',
    ];

    /** App do Security Monitor no Slack configurado no .env: sem isso só o webhook colado funciona. */
    public static function oauthConfigured(): bool
    {
        return filled(config('secmon.alerts.slack.client_id')) && filled(config('secmon.alerts.slack.client_secret'));
    }

    public static function isWebhookUrl(?string $url): bool
    {
        return is_string($url) && (preg_match(self::WEBHOOK, $url) === 1 || self::isWorkflowUrl($url));
    }

    /** Webhook de fluxo de trabalho ("Começa com um webhook" no Workflow Builder). */
    public static function isWorkflowUrl(?string $url): bool
    {
        return is_string($url) && preg_match(self::WORKFLOW, $url) === 1;
    }

    /** "Adicionar ao Slack": o cliente escolhe o canal na tela do Slack (só o escopo do Incoming Webhook). */
    public function authorizeUrl(string $state, string $redirect): string
    {
        return 'https://slack.com/oauth/v2/authorize?'.http_build_query([
            'client_id' => config('secmon.alerts.slack.client_id'),
            'scope' => 'incoming-webhook',
            'redirect_uri' => $redirect,
            'state' => $state,
        ]);
    }

    /** Troca o `code` do retorno pela instalação (token, time e webhook do canal). Nulo quando o Slack não respondeu. */
    public function exchangeCode(string $code, string $redirect): ?array
    {
        try {
            $response = Http::asForm()->timeout(10)->post(self::API.'/oauth.v2.access', [
                'client_id' => config('secmon.alerts.slack.client_id'),
                'client_secret' => config('secmon.alerts.slack.client_secret'),
                'code' => $code,
                'redirect_uri' => $redirect,
            ])->json();
        } catch (\Throwable) {
            return null;
        }

        return is_array($response) ? $response : null;
    }

    /** Revoga o token da instalação pelo app (melhor esforço). */
    public function revoke(string $token): void
    {
        try {
            Http::asForm()->timeout(5)->post(self::API.'/auth.revoke', ['token' => $token]);
        } catch (\Throwable) {
            // o canal sai daqui de qualquer jeito
        }
    }

    public function post(NotificationChannel $channel, array $payload): SlackResult
    {
        $result = $this->postOnce($channel, $payload);
        $images = collect($payload['blocks'] ?? [])->where('type', 'image');

        if (! $result->ok && $images->isNotEmpty() && in_array($result->error, ['invalid_blocks', 'http_400'], true)) {
            $payload['blocks'] = collect($payload['blocks'])->reject(fn ($block) => ($block['type'] ?? null) === 'image')->values()->all();
            $result = $this->postOnce($channel, $payload);
        }

        return $result;
    }

    private function postOnce(NotificationChannel $channel, array $payload): SlackResult
    {
        $url = (string) $channel->webhook_url;

        if (! self::isWebhookUrl($url)) {
            return SlackResult::permanent(0, 'invalid_webhook', breaksChannel: true);
        }

        try {
            $response = Http::timeout((int) config('secmon.alerts.slack.timeout', 10))
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->asJson()
                ->post($url, self::isWorkflowUrl($url) ? WorkflowVariables::from($payload) : $payload);
        } catch (ConnectionException $e) {
            return SlackResult::transient(0, 'connection');
        }

        $status = $response->status();
        $body = trim($response->body());

        if ($response->successful()) {
            return SlackResult::ok($status);
        }

        if ($status === 429) {
            return SlackResult::rateLimited($status, (int) ($response->header('Retry-After') ?: 30));
        }

        // Incoming Webhook responde o erro em texto ("no_service"); o de fluxo, em JSON.
        $error = $response->json('error') ?? $body;
        $error = is_string($error) && preg_match('/^[a-z_]{2,60}$/', $error) ? $error : 'http_'.$status;

        if (in_array($status, [403, 404, 410], true) || in_array($error, self::CHANNEL_GONE, true)) {
            return SlackResult::permanent($status, $error, breaksChannel: true);
        }

        if ($status >= 400 && $status < 500) {
            return SlackResult::permanent($status, $error, breaksChannel: false);
        }

        return SlackResult::transient($status, $error);
    }
}
