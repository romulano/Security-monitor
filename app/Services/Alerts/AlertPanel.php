<?php

namespace App\Services\Alerts;

use App\Exceptions\ActionRefused;
use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Access\Destinations;
use App\Services\Access\Visibility;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * /alertas: o painel do cliente — os alertas dele (um por produto ou site),
 * os canais e os alertas recebidos.
 */
class AlertPanel
{
    public const FILTERS = [
        'todos' => 'Todos',
        'na-hora' => 'Na hora',
        'resumo' => 'Só no resumo',
        'pendentes' => 'Pendentes',
        'relevantes' => 'Relevantes',
        'nao-relevantes' => 'Não relevantes',
        'segurados-pela-ia' => 'Segurados pela IA',
    ];

    public function __construct(
        private ProfileIndex $index,
        private Notifier $notifier,
        private Visibility $visibility,
    ) {}

    /**
     * Os alertas do cliente, o histórico no filtro escolhido e os números do
     * topo. `$alertId` (o `?alerta=`) restringe o histórico a um alerta concluído.
     */
    public function page(User $user, string $filter, int $alertId): array
    {
        $profiles = AlertProfile::query()->visibleTo($user)->withCount(['techs', 'terms'])->with(['domains.findings'])->oldest('id')->get();
        $completed = $profiles->whereNotNull('completed_at');
        $only = $completed->firstWhere('id', $alertId);

        $matches = null;
        $stats = null;

        if ($completed->isNotEmpty()) {
            $mine = AlertMatch::query()->whereIn('profile_id', $only ? [$only->id] : $completed->pluck('id'));
            $base = (clone $mine)->relevant();
            // O que a IA segurou não é alerta, mas fica à vista: marcar como
            // relevante é o que ensina a IA a deixar passar da próxima vez.
            $held = (clone $mine)->where('suppressed_reason', 'ai_low_relevance');

            // "Todos" mostra também o que a IA segurou (pintado de violeta na lista).
            $listed = (clone $mine)->where(fn ($q) => $q->whereNull('suppressed_reason')->orWhere('suppressed_reason', 'ai_low_relevance'));

            // "Relevantes": o que a IA aprovou (ou nem precisou julgar, como a menção à
            // empresa) mais o que o cliente marcou como relevante. O voto dele vence a
            // IA nos dois sentidos: "não é relevante" tira da lista, "relevante" põe.
            $relevantOnly = fn ($q) => $q->where(fn ($q) => $q->where('feedback', 'relevant')->orWhere(fn ($q) => $q
                ->whereNull('suppressed_reason')
                ->where(fn ($q) => $q->whereNull('feedback')->orWhere('feedback', '!=', 'irrelevant'))));

            // "Pendentes": o que a IA deixou passar (ou nem julgou) e o cliente ainda
            // não avaliou. O que ainda está em análise pela IA não entra.
            $pendingOnly = fn ($q) => $q->whereNull('suppressed_reason')->whereNull('feedback')
                ->where(fn ($q) => $q->whereNull('delivery_note')->orWhere('delivery_note', '!=', 'ai_review'));

            $matches = ($filter === 'segurados-pela-ia' ? clone $held : clone $listed)
                ->with(['case:id,uuid,title,title_pt,criticity', 'profile:id,name,company_name,language'])
                ->when($filter === 'pendentes', $pendingOnly)
                ->when($filter === 'relevantes', $relevantOnly)
                ->when($filter === 'na-hora', fn ($q) => $q->where('delivery_status', 'sent'))
                ->when($filter === 'resumo', fn ($q) => $q->where('delivery_status', 'skipped')->whereIn('delivery_note', ['realtime_off', 'no_channel']))
                ->when($filter === 'nao-relevantes', fn ($q) => $q->where('feedback', 'irrelevant'))
                ->latest('matched_at')
                ->paginate(20)
                ->withQueryString();

            $recent = (clone $base)->where('matched_at', '>=', now()->subDays(30));
            $stats = [
                'total' => (clone $recent)->count(),
                'by_level' => (clone $recent)->selectRaw('criticity, count(*) as n')->groupBy('criticity')->pluck('n', 'criticity'),
                'irrelevant' => (clone $recent)->where('feedback', 'irrelevant')->count(),
                'held' => (clone $held)->where('matched_at', '>=', now()->subDays(30))->count(),
            ];

            // Números dos chips: os mesmos que a lista mostra ao clicar (sem corte de 30 dias).
            $counts = [
                'pendentes' => (clone $listed)->tap($pendingOnly)->count(),
                'relevantes' => (clone $listed)->tap($relevantOnly)->count(),
                'segurados-pela-ia' => (clone $held)->count(),
            ];
        }

        return [
            'profiles' => $profiles,
            'completed' => $completed,
            'only' => $only,
            'channels' => $user->notificationChannels,
            'matches' => $matches,
            'stats' => $stats,
            'filter' => $filter,
            'aiCut' => Settings::int('alerts.ai_review_threshold'),
            'counts' => $counts ?? [],
            'canCreate' => $canCreate = $this->canCreate($user),
            'managed' => AlertProfile::query()->manageableBy($user)->pluck('id')->all(),
        ];
    }

    /** O "Novo alerta" aparece enquanto a conta está abaixo do limite dela. */
    public function canCreate(User $user): bool
    {
        return $user->alertProfilesCreated() < AlertProfile::MAX_PER_USER;
    }

    /** Alerta novo só enquanto o cliente (ou a empresa dele) não chegou ao limite; a trava confere de novo no cadastro. */
    public function ensureRoomFor(User $user): void
    {
        if ($user->isCompanyAccount() && $this->limits->alertsLeft($user) === 0) {
            throw new ActionRefused($this->limits->alertsFullMessage($user, $this->limits->alertsMax($user)));
        }

        if (! $user->isCompanyAccount() && $user->alertProfilesCreated() >= AlertProfile::MAX_PER_USER) {
            throw new ActionRefused('Você chegou ao limite de '.AlertProfile::MAX_PER_USER.' alertas. Exclua um para criar outro.');
        }
    }

    /** Pausa ou retoma o alerta (e tira ou devolve a query ao percolator); devolve se ficou pausado. */
    public function togglePause(AlertProfile $profile): bool
    {
        $paused = ! $profile->isPaused();
        $profile->forceFill(['status' => $paused ? 'paused' : 'active', 'paused_at' => $paused ? now() : null])->save();
        $this->index->put($profile);

        return $paused;
    }

    /**
     * O alerta sai do percolator e do banco, com respostas, domínio e histórico (a /privacidade
     * promete). As entregas de um alerta só apontavam para ele por match_id, que o Postgres zera
     * (set null) quando o alerta é apagado: o texto enviado ficava para trás. Saem junto.
     *
     * Fora daqui ficam as entregas agrupadas e de resumo, que misturam alertas (os ids ficam em
     * meta): o secmon:prune tira o texto delas por prazo.
     */
    public function delete(AlertProfile $profile): void
    {
        $this->index->forget($profile);

        DB::transaction(function () use ($profile) {
            NotificationDelivery::query()
                ->whereIn('match_id', AlertMatch::query()->where('profile_id', $profile->id)->select('id'))
                ->delete();

            $profile->delete();
            $this->limits->notify($profile->company_id, 'alert_profiles');
        });
    }

    /**
     * Manda de novo um alerta do histórico para os canais dele que recebem
     * notícias (conectados, sem pausa e com as notícias ligadas em Notificadores),
     * mesmo que já tenha ido. Os canais são os do alerta (Destinations: na empresa,
     * os do escopo dele), nunca os de quem clica. Devolve os canais que receberam,
     * ou null se o alerta já está na fila de envio.
     *
     * @return ?Collection<int, NotificationChannel>
     */
    public function resend(AlertMatch $match, User $user): ?Collection
    {
        if ($match->case?->isHidden()) {
            throw new ActionRefused('Essa matéria saiu do feed público (não é de segurança) e não é mais enviada.');
        }

        $channels = app(Destinations::class)->for($match);
        $ready = $channels->filter->receives('news');

        if ($ready->isEmpty()) {
            throw new ActionRefused(self::resendRefusal($channels));
        }

        if ($match->delivery_status === 'pending') {
            return null;
        }

        foreach ($ready as $channel) {
            $this->notifier->alert($match, $channel, resent: true);
        }

        return $ready;
    }

    /**
     * Por que nenhum app recebe o reenvio: o que o cliente resolve mais
     * rápido vem primeiro (retomar, reconectar, escolher o canal, ligar as notícias).
     *
     * @param  Collection<int, NotificationChannel>  $channels
     */
    private static function resendRefusal(Collection $channels): string
    {
        $by = $channels->groupBy(fn (NotificationChannel $channel) => $channel->skipReason('news'));

        return match (true) {
            $channels->isEmpty() => 'Conecte o Slack, o Discord, o Telegram ou um webhook em Notificadores para reenviar alertas.',
            $by->has('channel_paused') => 'O envio para o '.$by['channel_paused']->map->appName()->join(', ', ' e o ').' está pausado. Retome em Notificadores para reenviar.',
            $by->has('channel_broken') => 'O '.$by['channel_broken']->first()->appName().' precisa ser reconectado antes de reenviar.',
            $by->has('no_channel') && $by['no_channel']->every->isWebhook() => 'Comprove o domínio do '.$by['no_channel']->first()->appName().' antes de reenviar.',
            $by->has('no_channel') => 'Escolha o canal do Discord antes de reenviar.',
            default => 'Nenhum app recebe notícias. Ligue as notícias em um deles em Notificadores para reenviar.',
        };
    }

    /** O cliente já conectou algum app (Slack, Discord, Telegram ou webhook)? */
    public function hasChannel(User $user): bool
    {
        return $user->notificationChannels()->exists();
    }
}
