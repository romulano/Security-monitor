<?php

namespace App\Services\Github;

use App\Exceptions\ActionRefused;
use App\Jobs\ScanGithub;
use App\Models\GithubFinding;
use App\Models\GithubMonitor;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Company\CompanyLimits;
use App\Services\Company\Destinations;
use App\Services\Company\ItemSectors;
use App\Services\Company\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * /alertas/github: o monitoramento do GitHub do cliente — o convite para a
 * entrevista, o estado da varredura e os achados, com as duas análises da IA,
 * os filtros e a avaliação; e os botões "Verificar agora", pausar e excluir.
 */
class GithubPanel
{
    public const FILTERS = [
        'todos' => 'Todos',
        'enviados' => 'Enviados',
        'pendentes' => 'Pendentes',
        'relevantes' => 'Relevantes',
        'nao-relevantes' => 'Não relevantes',
        'segurados-pela-ia' => 'Segurados pela IA',
        'abaixo-do-nivel' => 'Abaixo do seu nível',
        'descartados' => 'Descartados',
    ];

    /** Saíram ou deviam sair (o que a IA aprovou, mesmo que um app não tenha recebido). */
    private const DELIVERED = ['sent', 'queued', 'skipped', 'failed'];

    public function __construct(private GithubAlertRouter $router, private Visibility $visibility, private GithubInterview $interview, private ItemSectors $sectors, private Destinations $destinations, private CompanyLimits $limits) {}

    /** @param  Collection<int, GithubMonitor>  $monitors  os que a conta vê; `$monitor`, o escolhido (`?monitor=`) */
    public function page(User $user, Collection $monitors, ?GithubMonitor $monitor, ?string $filter): array
    {
        $chosen = array_key_exists((string) $filter, self::FILTERS) ? $filter : 'todos';
        $findings = null;
        $counts = [];

        if ($monitor?->isCompleted()) {
            $base = $monitor->findings();
            $filters = $this->filters();

            foreach ($filters as $key => $scope) {
                $counts[$key] = (clone $base)->tap($scope)->count();
            }

            $findings = (clone $base)->tap($filters[$chosen])->byLevel()->paginate(25)->withQueryString();
        }

        // Os canais do monitoramento: os de quem o criou, na conta pessoal; os do escopo dele, na empresa (E5b1).
        $channels = $monitor ? $this->destinations->for($monitor) : $user->notificationChannels;
        $manage = $monitor !== null && $this->visibility->manages($user, $monitor);
        $company = $manage && $monitor->company_id !== null;
        $choices = $company ? $this->visibility->sectorChoices($user) : collect();

        return [
            'monitors' => $monitors,
            'monitor' => $monitor,
            'filter' => $chosen,
            'findings' => $findings,
            'counts' => $counts,
            'channels' => $channels,
            'receivers' => $channels->filter(fn (NotificationChannel $c) => $c->receives('github'))->values(),
            // O aviso de "escolher onde receber" só para quem criou: os canais são dele.
            'ownChannels' => $monitor === null || $monitor->user()->is($user),
            // O que esta conta faz aqui (R14): configurar, criar outro e, no de empresa, trocar o setor.
            'canManage' => $manage,
            'canCreate' => $this->interview->mayCreate($user),
            'sectorChoices' => $choices,
            'sectorless' => $company && $this->visibility->canUseNoSector($user),
            'mutedIn' => $company ? $this->sectors->mutedIn($monitor, $choices->modelKeys()) : [],
            'aiCut' => app(GithubJudge::class)->threshold(),
            'codeSearch' => app(GithubClient::class)->hasToken(),
        ];
    }

    /** @return array<string, callable(Builder|HasMany): mixed> */
    private function filters(): array
    {
        return [
            // "Todos" mostra o que a IA segurou (em violeta) e o que ficou abaixo do nível; o descartado tem filtro próprio.
            'todos' => fn ($q) => $q->where('status', '!=', 'discarded'),
            'enviados' => fn ($q) => $q->where('status', 'sent'),
            // O que a IA deixou passar e o cliente ainda não avaliou.
            'pendentes' => fn ($q) => $q->whereIn('status', self::DELIVERED)->whereNull('feedback'),
            // O voto do cliente vence a IA nos dois sentidos, como em Meus alertas.
            'relevantes' => fn ($q) => $q->where(fn ($q) => $q->where('feedback', 'relevant')
                ->orWhere(fn ($q) => $q->whereIn('status', self::DELIVERED)->whereNull('feedback'))),
            'nao-relevantes' => fn ($q) => $q->where('feedback', 'irrelevant'),
            'segurados-pela-ia' => fn ($q) => $q->where('status', 'held'),
            'abaixo-do-nivel' => fn ($q) => $q->where('status', 'below_level'),
            'descartados' => fn ($q) => $q->where('status', 'discarded'),
        ];
    }

    /** Um achado com tudo o que a tela de detalhe mostra. */
    public function detail(User $user, GithubFinding $finding): array
    {
        $finding->load('monitor');

        return [
            'finding' => $finding,
            'monitor' => $finding->monitor,
            // Reenviar é configurar (R14): o membro vê e vota, mas não dispara os canais de quem criou.
            'canManage' => $this->visibility->manages($user, $finding->monitor),
            'receivers' => $this->destinations->for($finding->monitor)->filter(fn (NotificationChannel $c) => $c->receives('github'))->values(),
            'aiCut' => app(GithubJudge::class)->threshold(),
        ];
    }

    /** "Verificar agora": 1 vez a cada 24 h (a varredura diária continua). */
    public function scanNow(GithubMonitor $monitor): void
    {
        if (! $monitor->isActive()) {
            throw new ActionRefused($monitor->isCompleted() ? 'O monitoramento está pausado. Retome para verificar.' : 'Conclua a entrevista para ativar o monitoramento.');
        }

        if ($at = $monitor->manualScanAvailableAt()) {
            throw new ActionRefused('Já verificamos hoje. A próxima verificação manual fica liberada em '
                .$at->timezone('America/Sao_Paulo')->format('d/m \à\s H:i').'.');
        }

        $monitor->forceFill(['manual_scan_at' => now(), 'scan_status' => 'pending'])->save();
        ScanGithub::dispatch($monitor->id);
    }

    /** Pausa ou retoma; devolve se ficou pausado. Ao retomar, a próxima varredura fica para já. */
    public function togglePause(GithubMonitor $monitor): bool
    {
        if (! $monitor->isCompleted()) {
            throw new ActionRefused('Conclua a entrevista para ativar o monitoramento.');
        }

        $paused = $monitor->isActive();
        $monitor->forceFill(['status' => $paused ? 'paused' : 'active', 'next_scan_at' => $paused ? $monitor->next_scan_at : now()])->save();

        return $paused;
    }

    /** Apaga o monitoramento, as respostas e os achados. */
    public function delete(GithubMonitor $monitor): void
    {
        $monitor->delete();
        $this->limits->notify($monitor->company_id, 'github_monitors');
    }

    /**
     * Manda o achado para os apps que recebem o GitHub, mesmo que a IA tenha
     * segurado ou que já tenha ido. Devolve os apps.
     *
     * @return Collection<int, NotificationChannel>
     */
    public function resend(GithubFinding $finding): Collection
    {
        if ($finding->status === 'queued') {
            throw new ActionRefused('Este achado já está na fila de envio.');
        }

        $receivers = $this->destinations->for($finding->monitor)->filter(fn (NotificationChannel $c) => $c->receives('github'))->values();

        if ($receivers->isEmpty()) {
            throw new ActionRefused('Nenhum app recebe o GitHub agora. Ligue o GitHub num app em Notificadores.');
        }

        $finding->forceFill(['status' => 'queued', 'note' => null])->save();

        foreach ($receivers as $channel) {
            $this->router->send($finding, $channel, resent: true);
        }

        return $receivers;
    }
}
