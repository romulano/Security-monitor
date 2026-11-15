<?php

namespace App\Services\Company;

use App\Exceptions\ActionRefused;
use App\Models\Company;
use App\Models\NotificationChannel;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Os canais de aviso da empresa (R11, R14, D1; DESENHO-5.4 §2.3): o único lugar que decide se uma conta conecta um canal
 * num escopo — os gerais (só quem administra, P2) ou um setor (quem administra e quem responde por ele) —, com os
 * limites da empresa (5 gerais, 5 por setor, 10 webhooks) e sem conversa privada (P1). A conta pessoal passa direto:
 * os limites dela (um de cada app, 5 webhooks) continuam nos conectores, como sempre.
 */
class CompanyChannels
{
    public const PRIVATE_REFUSED = 'Os avisos da empresa vão para um lugar da equipe (um grupo do Telegram, um canal de servidor do Discord ou um canal do Slack), '
        .'nunca para a conversa particular de uma pessoa: assim, se alguém sai da empresa, os avisos não vão junto.';

    public function __construct(private Visibility $visibility, private Companies $companies, private CompanyAudit $audit, private CompanyLimits $limits) {}

    /**
     * O escopo que a conta pede: a pessoal, o dela; a de empresa, os gerais (`geral`) ou um setor da empresa dela (o id).
     * Só lê o pedido: quem pode configurar ali é o manages()/claim().
     */
    public function scope(User $user, ?string $choice): ChannelScope
    {
        $viewer = $this->visibility->viewer($user);

        if ($viewer->kind === Viewer::PERSONAL) {
            return ChannelScope::personal($user->id);
        }

        if ($viewer->companyId === null || $viewer->kind === Viewer::NOTHING) {
            throw new ActionRefused('Esta conta não conecta canais agora.');
        }

        if ($choice === 'geral') {
            return ChannelScope::general($viewer->companyId);
        }

        $sector = ctype_digit((string) $choice) ? Sector::query()->whereKey((int) $choice)->where('company_id', $viewer->companyId)->first() : null;

        return $sector ? ChannelScope::sector($viewer->companyId, $sector->id)
            : throw new ActionRefused('Escolha onde o canal recebe: nos gerais da empresa ou num setor.');
    }

    /** A conta configura canais neste escopo? Pessoal: o dono. Gerais: só quem administra. Setor: quem administra e quem responde por ele. */
    public function manages(User $user, ChannelScope $scope): bool
    {
        $viewer = $this->visibility->viewer($user);

        return match (true) {
            $scope->isPersonal() => $viewer->kind === Viewer::PERSONAL && $scope->userId === $user->id,
            $viewer->companyId !== $scope->companyId => false,
            $scope->isGeneral() => $this->visibility->canUseNoSector($user),
            default => $this->visibility->sectorChoices($user)->contains('id', $scope->sectorId),
        };
    }

    /**
     * Um canal novo neste escopo: confere quem conecta, o setor (pode ter sido apagado agora), a conversa privada e os
     * limites, com a empresa travada (a mesma trava do apagar setor e dos outros limites). Chame dentro da transação que
     * grava o canal e grave as colunas devolvidas por forceFill.
     *
     * @return array{user_id: int, company_id: ?int, sector_id: ?int}
     */
    public function claim(User $by, ChannelScope $scope, string $type, ?string $method = null): array
    {
        if ($scope->isPersonal()) {
            return $this->manages($by, $scope) ? $scope->columns($by->id) : throw new ActionRefused('Esta conta não conecta canais aqui.');
        }

        $company = Company::query()->lockForUpdate()->find($scope->companyId) ?? throw new ActionRefused('Esta conta não conecta canais aqui.');
        ItemSectors::ensureSectorExists($scope->sectorId);

        if (! $this->manages($by, $scope)) {
            throw new ActionRefused($scope->isGeneral()
                ? 'Só quem administra a empresa conecta canais gerais.'
                : 'Só quem responde pelo setor (ou quem administra a empresa) conecta canais nele.');
        }

        if (($type === 'telegram' && $method === 'private') || ($type === 'discord' && $method === 'dm')) {
            throw new ActionRefused(self::PRIVATE_REFUSED);
        }

        $limit = $this->companies->limit($company, (string) $scope->limitName());

        if ($scope->constrain(NotificationChannel::query())->count() >= $limit) {
            throw new ActionRefused($scope->isGeneral()
                ? "A empresa já tem {$limit} canais gerais, o máximo. Remova um para conectar outro."
                : "O setor {$this->sectorName($scope)} já tem {$limit} canais, o máximo. Remova um para conectar outro.");
        }

        $webhooks = $this->companies->limit($company, 'webhooks');

        if ($type === 'webhook' && NotificationChannel::query()->where('company_id', $company->id)->where('type', 'webhook')->count() >= $webhooks) {
            throw new ActionRefused("A empresa já tem {$webhooks} webhooks, o máximo. Para conectar outro, desconecte um em Notificadores. ".CompanyLimits::MORE);
        }

        return $scope->columns($by->id);
    }

    /**
     * O canal que um conector (Slack, Discord, Telegram) vai gravar, ainda sem salvar: um que já existe (pessoal ou da
     * empresa, que a conta configura), o pessoal do app (forUser, só da conta pessoal) ou um novo da empresa no escopo.
     * A conta de empresa sempre diz onde (não tem canal pessoal, R11).
     */
    public function target(User $user, string $type, ?ChannelScope $scope = null, ?NotificationChannel $existing = null): NotificationChannel
    {
        if ($existing !== null) {
            return $existing->type === $type && $this->visibility->manages($user, $existing)
                ? $existing : throw new ActionRefused('Este canal não está entre os que você configura.');
        }

        $scope ??= $this->visibility->viewer($user)->kind === Viewer::PERSONAL ? ChannelScope::personal($user->id)
            : throw new ActionRefused('Escolha onde o canal recebe: nos gerais da empresa ou num setor.');

        if (! $this->manages($user, $scope)) {
            throw new ActionRefused($scope->isGeneral()
                ? 'Só quem administra a empresa conecta canais gerais.'
                : 'Só quem responde pelo setor (ou quem administra a empresa) conecta canais nele.');
        }

        return $scope->isPersonal()
            ? NotificationChannel::forUser($user->id, $type)
            : (new NotificationChannel(NotificationChannel::defaults() + ['type' => $type]))->forceFill($scope->columns($user->id));
    }

    /**
     * Grava o que o conector montou. O canal novo da empresa passa pelo claim (quem, o setor, a conversa privada e os
     * limites, com a empresa travada) e entra na Atividade; o que já existe confere de novo quem configura e a conversa
     * privada (a pessoa pode ter perdido o papel no meio do OAuth). O pessoal só grava, como sempre.
     */
    public function save(User $by, NotificationChannel $channel, array $attributes): NotificationChannel
    {
        return DB::transaction(function () use ($by, $channel, $attributes) {
            $channel->forceFill($attributes);
            $new = ! $channel->exists;

            if ($channel->isCompany() && $new) {
                $channel->forceFill($this->claim($by, ChannelScope::of($channel), (string) $channel->type, $channel->method));
            } elseif ($channel->isCompany()) {
                if (! $this->visibility->manages($by, $channel)) {
                    throw new ActionRefused('Este canal não está entre os que você configura.');
                }

                if (self::isPrivate($channel)) {
                    throw new ActionRefused(self::PRIVATE_REFUSED);
                }
            }

            $channel->save();

            if ($new) {
                $this->record($by, $channel, 'channel.added');
            }

            return $channel;
        });
    }

    /**
     * O alvo de um conector que atravessa a volta do app (o OAuth na sessão, o código do Telegram no cache): o canal
     * existente ou o escopo. Vazio = o canal pessoal. Só ids: quem pode é conferido de novo na volta (target/save).
     *
     * @return array{channel?: int, company?: int, sector?: ?int}
     */
    public static function pack(?ChannelScope $scope, ?NotificationChannel $existing = null): array
    {
        return match (true) {
            $existing !== null => ['channel' => $existing->id],
            $scope !== null && ! $scope->isPersonal() => ['company' => $scope->companyId, 'sector' => $scope->sectorId],
            default => [],
        };
    }

    /** O alvo guardado de volta: o canal (de novo pelo target, que confere quem configura) ou o escopo. */
    public function unpack(User $user, mixed $packed, string $type): NotificationChannel
    {
        $packed = is_array($packed) ? $packed : [];

        return match (true) {
            isset($packed['channel']) => $this->target($user, $type, existing: NotificationChannel::query()->find((int) $packed['channel'])
                ?? throw new ActionRefused('O canal foi removido enquanto você conectava. Tente de novo.')),
            isset($packed['company']) => $this->target($user, $type, isset($packed['sector'])
                ? ChannelScope::sector((int) $packed['company'], (int) $packed['sector']) : ChannelScope::general((int) $packed['company'])),
            default => $this->target($user, $type),
        };
    }

    /** O alvo é da empresa (para recusar a conversa privada antes de ir ao app). */
    public static function packedIsCompany(mixed $packed): bool
    {
        return is_array($packed) && (isset($packed['company']) || isset($packed['channel']) && NotificationChannel::query()->whereKey((int) $packed['channel'])->whereNotNull('company_id')->exists());
    }

    /** Conversa de uma pessoa: Telegram privado (ou com o ID positivo de uma pessoa), DM do Discord, DM do Slack. */
    public static function isPrivate(NotificationChannel $channel): bool
    {
        return ($channel->type === 'telegram' && ($channel->method === 'private' || ($channel->telegram_chat_id !== null && ! str_starts_with((string) $channel->telegram_chat_id, '-'))))
            || ($channel->type === 'discord' && $channel->method === 'dm');
    }

    /** Conectou ou removeu um canal da empresa: entra na Atividade (o app e onde, nunca a URL). O pessoal não registra nada. */
    public function record(User $by, NotificationChannel $channel, string $action): void
    {
        if (! $channel->isCompany()) {
            return;
        }

        $this->audit->record((int) $channel->company_id, $by, $action, targetLabel: $channel->appName(), data: [
            'onde' => $channel->sector_id === null ? 'gerais' : 'setor '.$this->sectorName(ChannelScope::of($channel)),
        ]);

        // Os avisos de 80%/100% (E5d): o escopo do canal e, se é webhook, os webhooks da empresa.
        $this->limits->notify((int) $channel->company_id, $channel->sector_id === null ? 'general_channels' : 'sector_channels', $channel->sector_id);

        if ($channel->type === 'webhook') {
            $this->limits->notify((int) $channel->company_id, 'webhooks');
        }
    }

    /** O nome do setor do escopo, para as telas dizerem onde o canal recebe; nulo nos canais gerais. */
    public function sectorLabel(ChannelScope $scope): ?string
    {
        return $scope->isGeneral() ? null : $this->sectorName($scope);
    }

    private function sectorName(ChannelScope $scope): string
    {
        return (string) Sector::query()->whereKey($scope->sectorId)->value('name');
    }
}
