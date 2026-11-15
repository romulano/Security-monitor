<?php

namespace App\Services\Alerts\Discord;

use App\Exceptions\ActionRefused;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Alerts\ChannelSettings;
use App\Services\Alerts\Notifier;
use App\Services\Company\ChannelScope;
use App\Services\Company\CompanyChannels;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * /alertas/discord: conectar e desconectar. Três jeitos: o bot num canal do
 * servidor (para a equipe), o bot em mensagem direta (só para quem conectou)
 * ou um webhook colado, que não precisa do bot.
 */
class DiscordConnector
{
    private const STATE = 'discord_oauth';

    public function __construct(
        private DiscordClient $discord,
        private MessageBuilder $messages,
        private Notifier $notifier,
        private ChannelSettings $settings,
        private CompanyChannels $channels,
    ) {}

    /** `$pickChannel` (`?canal=1`): trocar o canal do servidor já escolhido. */
    public function page(User $user, ?NotificationChannel $channel, bool $changing, bool $pickChannel): array
    {
        // Escolher (ou trocar) o canal do servidor: a lista vem do Discord na hora.
        $picking = $channel?->method === 'channel' && $channel->discord_guild_id && ($channel->status === 'pending' || $pickChannel);

        return $this->settings->page($user, $channel, $changing) + [
            'available' => DiscordClient::configured(),
            'picking' => $picking,
            'guildChannels' => $picking ? $this->discord->textChannels($channel->discord_guild_id) : null,
        ];
    }

    /**
     * "Adicionar a um servidor" (`canal`), "Receber em mensagem direta" (`dm`)
     * ou "Entrar na comunidade do Security Monitor" (`comunidade`), a oferta para quem
     * não divide nenhum servidor com o bot. Guarda o state na sessão e
     * devolve a tela do Discord.
     */
    public function authorizeUrl(string $modo, Session $session, ?User $user = null, ?ChannelScope $scope = null, ?NotificationChannel $existing = null): string
    {
        $state = Str::random(40);
        $mode = ['dm' => 'dm', 'comunidade' => 'dm_join'][$modo] ?? 'channel';
        $target = CompanyChannels::pack($scope, $existing);

        // Na empresa (E5a, P1): só o servidor; a mensagem direta seria a conversa de uma pessoa. Quem pode, já aqui.
        if ($mode !== 'channel' && CompanyChannels::packedIsCompany($target)) {
            throw new ActionRefused(CompanyChannels::PRIVATE_REFUSED, route('alerts.discord'));
        }

        if ($user !== null) {
            $this->channels->target($user, 'discord', $scope, $existing);
        }

        $session->put(self::STATE, ['state' => $state, 'mode' => $mode] + ($target === [] ? [] : ['target' => $target]));

        return $this->discord->authorizeUrl($mode, $state, route('alerts.discord.callback'));
    }

    /**
     * A volta do Discord: confere o state (de uso único), troca o código pelo
     * token e conecta o servidor ou a mensagem direta. `$code` nulo: o
     * cliente cancelou na tela do Discord. Nulo quando a mensagem direta foi
     * recusada por falta de servidor em comum com o bot e a tela deve
     * oferecer a comunidade do Security Monitor.
     */
    public function finishOAuth(User $user, Session $session, string $state, ?string $code): ?NotificationChannel
    {
        $expected = $session->pull(self::STATE);

        if (! is_array($expected) || ! hash_equals((string) ($expected['state'] ?? ''), $state)) {
            throw new ActionRefused('A conexão com o Discord expirou ou não começou aqui. Tente de novo.', route('alerts.discord'));
        }

        if ($code === null) {
            throw new ActionRefused('Conexão cancelada no Discord.', route('alerts.discord'));
        }

        $token = $this->discord->exchangeCode($code, route('alerts.discord.callback'));

        if (! $token) {
            throw new ActionRefused('O Discord não confirmou a autorização. Tente de novo.', route('alerts.discord'));
        }

        $target = $expected['target'] ?? [];

        // O token da conta do cliente só serve para saber quem ele é e qual servidor escolheu.
        try {
            if ($expected['mode'] !== 'channel' && CompanyChannels::packedIsCompany($target)) {
                throw new ActionRefused(CompanyChannels::PRIVATE_REFUSED, route('alerts.discord'));
            }

            return match ($expected['mode']) {
                'dm' => $this->connectDm($user, $token, join: false),
                'dm_join' => $this->connectDm($user, $token, join: true),
                default => $this->connectGuild($user, $token, $target),
            };
        } finally {
            $this->discord->revoke((string) $token['access_token']);
        }
    }

    /**
     * Escolhe o canal do servidor onde os alertas chegam e manda o teste.
     * Devolve o nome do canal, ou nulo quando `$channelId` não é um canal de
     * texto deste servidor.
     */
    public function selectChannel(NotificationChannel $channel, string $channelId): ?string
    {
        // Só o canal do bot num servidor: o da empresa nunca vira outra coisa por aqui (E5a, Red Team).
        if ($channel->method !== 'channel' || ! $channel->discord_guild_id) {
            throw new ActionRefused('Este Discord não está conectado a um servidor. Conecte de novo pelo botão "Adicionar a um servidor".');
        }

        $options = $this->discord->textChannels($channel->discord_guild_id);

        if ($options === null) {
            throw new ActionRefused('Não conseguimos ler os canais do servidor. Se o bot foi removido, conecte de novo.');
        }

        // Só canais deste servidor: o bot pode estar em servidores de outros clientes.
        $chosen = collect($options)->firstWhere('id', $channelId);

        if (! $chosen) {
            return null;
        }

        $channel->forceFill([
            'discord_channel_id' => $chosen['id'],
            'discord_channel_name' => mb_substr($chosen['name'], 0, 120),
            'status' => 'active',
            'broken_at' => null,
            'last_error' => null,
        ])->save();

        $this->notifier->test($channel);

        return $chosen['name'];
    }

    public function saveWebhook(User $user, string $url, ?ChannelScope $scope = null, ?NotificationChannel $existing = null): NotificationChannel
    {
        $channel = $this->channels->target($user, 'discord', $scope, $existing);
        $previousGuild = $channel->method === 'channel' ? $channel->discord_guild_id : null;

        $this->channels->save($user, $channel, [
            'method' => 'webhook',
            'webhook_url' => $url,
            ...$this->clearedTarget(),
            'status' => 'active',
            'broken_at' => null,
            'last_error' => null,
        ]);

        $this->leaveIfUnused($previousGuild);
        $this->notifier->test($channel);

        return $channel;
    }

    public function test(NotificationChannel $channel): void
    {
        if ($channel->status === 'pending') {
            throw new ActionRefused('Escolha o canal do servidor primeiro.');
        }

        $this->notifier->test($channel);
    }

    /** Desconecta; no canal da empresa, `$by` vai à Atividade. */
    public function disconnect(?NotificationChannel $channel, ?User $by = null): void
    {
        $guild = $channel?->method === 'channel' ? $channel->discord_guild_id : null;

        $channel?->delete();

        if ($channel !== null && $by !== null) {
            $this->channels->record($by, $channel, 'channel.removed');
        }

        $this->leaveIfUnused($guild);
    }

    private function connectGuild(User $user, array $token, mixed $target = []): NotificationChannel
    {
        $guild = $token['guild'] ?? null;

        if (! is_array($guild) || empty($guild['id'])) {
            throw new ActionRefused('O Discord não informou o servidor. Tente de novo e escolha um servidor na tela do Discord.', route('alerts.discord'));
        }

        $account = $this->discord->currentUser((string) $token['access_token']);
        $channel = $this->channels->unpack($user, $target, 'discord');
        $previousGuild = $channel->method === 'channel' ? $channel->discord_guild_id : null;

        $this->channels->save($user, $channel, [
            'method' => 'channel',
            'webhook_url' => null,
            ...$this->clearedTarget(),
            'discord_guild_id' => (string) $guild['id'],
            'discord_guild_name' => mb_substr((string) ($guild['name'] ?? ''), 0, 120) ?: null,
            'discord_user_id' => isset($account['id']) ? (string) $account['id'] : null,
            'discord_username' => $this->username($account),
            'status' => 'pending',
            'broken_at' => null,
            'last_error' => null,
        ]);

        if ($previousGuild !== $channel->discord_guild_id) {
            $this->leaveIfUnused($previousGuild);
        }

        return $channel;
    }

    /**
     * Mensagem direta. A mensagem de teste sai na hora, antes de salvar, porque
     * o Discord só recusa a DM no envio (abrir a conversa sempre dá certo). Sem
     * servidor em comum com o bot (50278), a tela oferece entrar na comunidade
     * do Security Monitor (nulo); aceitando (`$join`), o cliente entra nela e a DM passa.
     */
    private function connectDm(User $user, array $token, bool $join): ?NotificationChannel
    {
        // Só o canal pessoal recebe em mensagem direta (a empresa é recusada antes, no authorizeUrl e na volta). Confere
        // antes de pôr a pessoa na comunidade do Security Monitor e abrir a conversa (Red Team da E5a1b).
        $channel = $this->channels->target($user, 'discord');
        $account = $this->discord->currentUser((string) $token['access_token']);

        if (! $account) {
            throw new ActionRefused('Não conseguimos identificar a sua conta do Discord. Tente de novo.', route('alerts.discord'));
        }

        if ($join && ($home = DiscordClient::homeGuild())) {
            $joined = $this->discord->addToGuild($home, (string) $account['id'], (string) $token['access_token']);

            if (! $joined->ok) {
                throw new ActionRefused('Não conseguimos colocar você na comunidade do Security Monitor. '.$joined->humanError(), route('alerts.discord'));
            }
        }

        $dm = $this->discord->openDm((string) $account['id']);

        if (! $dm->ok || empty($dm->data['id'])) {
            throw new ActionRefused($dm->humanError(), route('alerts.discord'));
        }

        $channel->forceFill([
            'method' => 'dm',
            'webhook_url' => null,
            ...$this->clearedTarget(),
            'discord_channel_id' => (string) $dm->data['id'],
            'discord_user_id' => (string) $account['id'],
            'discord_username' => $this->username($account),
        ]);

        $payload = $this->messages->test($channel);
        $sent = $this->discord->post($channel, $payload);

        if (! $sent->ok) {
            if ($sent->error === 'discord_50278' && ! $join && DiscordClient::homeGuild()) {
                return null;
            }

            throw new ActionRefused($sent->humanError(), route('alerts.discord'));
        }

        $channel->forceFill(['status' => 'active', 'broken_at' => null, 'last_error' => null, 'last_success_at' => now()])->save();

        NotificationDelivery::query()->create([
            'channel_id' => $channel->id,
            'user_id' => $channel->user_id,
            'kind' => 'test',
            'status' => 'sent',
            'attempts' => 1,
            'response_code' => $sent->status,
            'payload' => $payload,
            'summary' => 'Mensagem de teste',
            'sent_at' => now(),
        ]);

        return $channel;
    }

    /** Zera o destino anterior ao trocar de jeito de receber. */
    private function clearedTarget(): array
    {
        return array_fill_keys(['discord_guild_id', 'discord_guild_name', 'discord_channel_id', 'discord_channel_name', 'discord_user_id', 'discord_username'], null);
    }

    /** O bot sai do servidor quando nenhum cliente recebe mais alertas nele (nunca do servidor do Security Monitor). */
    private function leaveIfUnused(?string $guildId): void
    {
        if (! $guildId || $guildId === DiscordClient::homeGuild()) {
            return;
        }

        $inUse = NotificationChannel::query()
            ->where('type', 'discord')
            ->where('method', 'channel')
            ->where('discord_guild_id', $guildId)
            ->exists();

        if (! $inUse) {
            $this->discord->leaveGuild($guildId);
        }
    }

    private function username(?array $account): ?string
    {
        $name = $account['global_name'] ?? $account['username'] ?? null;

        return $name ? mb_substr((string) $name, 0, 120) : null;
    }
}
