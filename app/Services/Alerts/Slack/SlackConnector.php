<?php

namespace App\Services\Alerts\Slack;

use App\Exceptions\ActionRefused;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Alerts\ChannelSettings;
use App\Services\Alerts\Notifier;
use App\Services\Company\ChannelScope;
use App\Services\Company\CompanyChannels;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * /alertas/slack: conectar o canal (webhook colado ou "Adicionar ao Slack") e desconectar. Na empresa (E5a), o canal é
 * dos gerais ou de um setor (`$scope`), ou um que já existe (`$existing`); o CompanyChannels confere quem e os limites.
 */
class SlackConnector
{
    private const STATE = 'slack_oauth_state';

    public const DM_REFUSED = 'O canal da empresa não pode ser uma conversa direta do Slack: na tela do Slack, escolha um canal do espaço de trabalho.';

    public function __construct(
        private SlackClient $slack,
        private Notifier $notifier,
        private ChannelSettings $settings,
        private CompanyChannels $channels,
    ) {}

    public function page(User $user, ?NotificationChannel $channel, bool $changing): array
    {
        return $this->settings->page($user, $channel, $changing) + [
            'oauthAvailable' => SlackClient::oauthConfigured(),
        ];
    }

    public function saveWebhook(User $user, string $url, ?ChannelScope $scope = null, ?NotificationChannel $existing = null): NotificationChannel
    {
        $channel = $this->channels->save($user, $this->channels->target($user, 'slack', $scope, $existing), [
            'method' => 'webhook',
            'webhook_url' => $url,
            'bot_token' => null,
            'slack_team' => null,
            'slack_channel' => null,
            'status' => 'active',
            'broken_at' => null,
            'last_error' => null,
        ]);

        $this->notifier->test($channel);

        return $channel;
    }

    /**
     * "Adicionar ao Slack": guarda o state na sessão (com onde o canal recebe, na empresa) e devolve a tela do Slack onde
     * o cliente escolhe o canal. Quem pode é conferido já aqui e de novo na volta.
     */
    public function authorizeUrl(Session $session, ?User $user = null, ?ChannelScope $scope = null, ?NotificationChannel $existing = null): string
    {
        if ($user !== null) {
            $this->channels->target($user, 'slack', $scope, $existing);
        }

        $state = Str::random(40);
        $target = CompanyChannels::pack($scope, $existing);
        // O canal pessoal guarda só o state, como sempre; o da empresa, também o alvo.
        $session->put(self::STATE, $target === [] ? $state : ['state' => $state, 'target' => $target]);

        return $this->slack->authorizeUrl($state, route('alerts.slack.callback'));
    }

    /**
     * A volta do Slack: confere o state (de uso único), troca o código pelo
     * webhook do canal escolhido, salva e manda o teste. `$code` nulo: o
     * cliente cancelou na tela do Slack.
     */
    public function finishOAuth(User $user, Session $session, string $state, ?string $code): NotificationChannel
    {
        $started = $session->pull(self::STATE);
        // O state de antes desta versão era só o texto (canal pessoal).
        [$expected, $target] = is_array($started) ? [(string) ($started['state'] ?? ''), $started['target'] ?? []] : [(string) $started, []];

        if ($expected === '' || ! hash_equals($expected, $state)) {
            throw new ActionRefused('A conexão com o Slack expirou ou não começou aqui. Tente de novo.', route('alerts.slack'));
        }

        if ($code === null) {
            throw new ActionRefused('Conexão cancelada no Slack.', route('alerts.slack'));
        }

        $response = $this->slack->exchangeCode($code, route('alerts.slack.callback'));
        $hook = $response['incoming_webhook']['url'] ?? null;

        if (! ($response['ok'] ?? false) || ! SlackClient::isWebhookUrl($hook)) {
            throw new ActionRefused('O Slack não confirmou a instalação ('.($response['error'] ?? 'resposta inválida').'). Tente de novo ou use o webhook.', route('alerts.slack'));
        }

        try {
            // P1 (R11): o canal da empresa não manda para a conversa direta de uma pessoa (o id do canal do Slack com D).
            if (CompanyChannels::packedIsCompany($target) && str_starts_with((string) ($response['incoming_webhook']['channel_id'] ?? ''), 'D')) {
                throw new ActionRefused(self::DM_REFUSED, route('alerts.slack'));
            }

            $channel = $this->saveOAuth($user, $target, $hook, $response);
        } catch (ActionRefused $refused) {
            // Recusado depois da instalação: o token não fica valendo no Slack (melhor esforço).
            if (filled($response['access_token'] ?? null)) {
                $this->slack->revoke((string) $response['access_token']);
            }

            throw $refused;
        }

        $this->notifier->test($channel);

        return $channel;
    }

    private function saveOAuth(User $user, mixed $target, string $hook, array $response): NotificationChannel
    {
        return $this->channels->save($user, $this->channels->unpack($user, $target, 'slack'), [
            'method' => 'oauth',
            'webhook_url' => $hook,
            'bot_token' => $response['access_token'] ?? null,
            'slack_team' => mb_substr((string) ($response['team']['name'] ?? ''), 0, 120) ?: null,
            'slack_channel' => mb_substr((string) ($response['incoming_webhook']['channel'] ?? ''), 0, 120) ?: null,
            'status' => 'active',
            'broken_at' => null,
            'last_error' => null,
        ]);
    }

    /** Desconecta; no canal da empresa, `$by` vai à Atividade. */
    public function disconnect(?NotificationChannel $channel, ?User $by = null): void
    {
        // Instalação pelo app: revoga o token também (melhor esforço).
        if ($channel?->bot_token) {
            $this->slack->revoke($channel->bot_token);
        }

        $channel?->delete();

        if ($channel !== null && $by !== null) {
            $this->channels->record($by, $channel, 'channel.removed');
        }
    }
}
