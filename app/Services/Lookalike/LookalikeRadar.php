<?php

namespace App\Services\Lookalike;

use App\Exceptions\ActionRefused;
use App\Jobs\ScanLookalikes;
use App\Models\LookalikeCapture;
use App\Models\LookalikeDomain;
use App\Models\LookalikeWatch;
use App\Models\User;
use App\Services\Company\ItemSectors;
use App\Services\Access\Visibility;
use App\Services\Domains\DomainOwnership;
use Illuminate\Support\Collection;

/**
 * /alertas/radar-de-dominios: nomes parecidos com o domínio de cada Radar do
 * cliente, com o nível de risco, os prints em cada aparelho, o parecer da IA
 * e o kit de denúncia; e os botões "Verificar agora" e pausar. O Radar nasce
 * na entrevista dele (RadarInterview), a partir da aba Integração.
 */
class LookalikeRadar
{
    public const FILTERS = [
        'ameacas' => 'Ameaças',
        'vigiar' => 'Vigiar',
        'baixo' => 'Baixo',
        'marcados' => 'Marcados por você',
        'todos' => 'Todos',
    ];

    public function __construct(
        private AbuseKit $kit,
        private DomainOwnership $ownership,
        private Visibility $visibility,
        private RadarInterview $interview,
        private ItemSectors $sectors,
    ) {}

    /**
     * A lista do Radar escolhido, com os contadores de cada filtro. Sem Radar,
     * ou com a entrevista dele em andamento, a página só guia o cliente.
     *
     * @param  Collection<int, LookalikeWatch>  $watches
     * @param  ?string  $filter  o pedido em `?filtro=` (nulo: nenhum)
     */
    public function listPage(User $user, Collection $watches, ?LookalikeWatch $watch, ?string $filter): array
    {
        $chosen = $filter !== null && array_key_exists($filter, self::FILTERS) ? $filter : 'ameacas';
        $lookalikes = null;
        $counts = [];

        if ($watch?->isCompleted()) {
            $base = $watch->lookalikes()->where('state', '!=', 'gone');
            $open = fn ($q) => $q->where(fn ($q) => $q->whereNull('verdict')->orWhere('verdict', 'threat'));

            $counts = [
                'critical' => (clone $base)->tap($open)->where('risk', 'critical')->count(),
                'high' => (clone $base)->tap($open)->where('risk', 'high')->count(),
                'watch' => (clone $base)->tap($open)->where('risk', 'watch')->count(),
                'low' => (clone $base)->tap($open)->where('risk', 'low')->count(),
                'marked' => (clone $base)->whereNotNull('verdict')->count(),
                'all' => (clone $base)->count(),
                'queued' => LookalikeCapture::query()->whereIn('lookalike_id', $watch->lookalikes()->select('id'))->where('status', 'queued')->count(),
            ];

            // Sem ameaça aberta, a página abre direto em "Vigiar" (ou em "Todos").
            if ($filter === null && $counts['critical'] + $counts['high'] === 0) {
                $chosen = $counts['watch'] ? 'vigiar' : 'todos';
            }

            $lookalikes = (clone $base)
                ->with(['lastCapture', 'latestAlert'])
                ->when($chosen === 'ameacas', fn ($q) => $q->tap($open)->whereIn('risk', ['critical', 'high']))
                ->when($chosen === 'vigiar', fn ($q) => $q->tap($open)->where('risk', 'watch'))
                ->when($chosen === 'baixo', fn ($q) => $q->tap($open)->where('risk', 'low'))
                ->when($chosen === 'marcados', fn ($q) => $q->whereNotNull('verdict'))
                ->byRisk()
                ->paginate(30)
                ->withQueryString();
        }

        return [
            'watches' => $watches,
            'watch' => $watch,
            'domain' => $watch?->domain,
            // A posse do domínio (a da conta): o TXT a criar, ou o aviso de que ele sumiu do DNS.
            'ownership' => $watch?->domain ? $this->ownership->screenFor($watch->domain) : null,
            'filter' => $chosen,
            'lookalikes' => $lookalikes,
            'counts' => $counts,
            // O que esta conta faz aqui (R14): configurar o Radar, criar outro e, no de empresa, trocar o setor.
            'canManage' => $manage = $watch !== null && $this->visibility->manages($user, $watch),
            'canCreate' => $this->interview->mayCreate($user) && ! $user->reachedRadarLimit(),
            'sectorChoices' => $manage && $watch->company_id !== null ? $this->visibility->sectorChoices($user) : collect(),
            'sectorless' => $manage && $watch->company_id !== null && $this->visibility->canUseNoSector($user),
            'mutedIn' => $manage && $watch->company_id !== null ? $this->sectors->mutedIn($watch, $this->visibility->sectorChoices($user)->modelKeys()) : [],
            // Os subdomínios que o Radar de empresa vigia (E4a); nulo fora da empresa ou com a chave desligada.
        ];
    }

    /** "Verificar agora": 1 vez a cada 24 h (a varredura das 01:00 continua). */
    public function scanNow(LookalikeWatch $watch): void
    {
        if (! $watch->isActive()) {
            throw new ActionRefused($watch->isWaiting()
                ? 'O Radar começa quando a posse do domínio for comprovada (registro TXT).'
                : 'Retome o Radar para verificar de novo.');
        }

        if ($at = $watch->manualScanAvailableAt()) {
            throw new ActionRefused('Já verificamos hoje. A próxima verificação manual fica liberada em '
                .$at->timezone('America/Sao_Paulo')->format('d/m \à\s H:i').'.');
        }

        $watch->forceFill(['manual_scan_at' => now(), 'scan_status' => 'pending'])->save();
        ScanLookalikes::dispatch($watch->id);
    }

    /** Pausa ou retoma o Radar; devolve se ficou pausado. Ao retomar, a próxima varredura fica para já. */
    public function togglePause(LookalikeWatch $watch): bool
    {
        if ($watch->isWaiting()) {
            throw new ActionRefused('O Radar ainda espera a comprovação do domínio: não há o que pausar.');
        }

        $paused = $watch->isActive();
        $watch->update(['status' => $paused ? 'paused' : 'active', 'next_scan_at' => $paused ? $watch->next_scan_at : now()]);

        return $paused;
    }

    /** Detalhe de um domínio: a última visita, o aviso e as visitas anteriores. */
    public function detailPage(LookalikeDomain $lookalike): array
    {
        $lookalike->load(['lastCapture', 'latestAlert', 'watch.domain']);

        return [
            'lookalike' => $lookalike,
            'watch' => $lookalike->watch,
            'captures' => $lookalike->captures()->where('status', 'done')->latest('captured_at')->limit(20)->get(),
        ];
    }

    /** Kit de denúncia: quem pode derrubar, o texto pronto, os prints e o que o cliente já enviou. */
    public function kitPage(LookalikeDomain $lookalike): array
    {
        $lookalike->load(['lastCapture', 'latestAlert', 'watch.domain']);

        return [
            'lookalike' => $lookalike,
            'watch' => $lookalike->watch,
            'kit' => $this->kit->for($lookalike),
        ];
    }
}
