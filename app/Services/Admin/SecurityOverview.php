<?php

namespace App\Services\Admin;

use App\Models\SecurityCheck;
use App\Models\SecurityEvent;
use App\Models\SecurityFinding;
use App\Models\SecuritySite;
use App\Services\Security\CheckCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Admin → Segurança: o que as telas mostram (lista, detalhe de um site e o cartão do painel). */
class SecurityOverview
{
    /** @return array<string, mixed> */
    public function index(): array
    {
        $sites = SecuritySite::query()
            ->with('lastCheck')
            ->withCount(['findings as open_findings' => fn ($q) => $q->where('status', 'open'), 'findings as open_critical' => fn ($q) => $q->where('status', 'open')->where('severity', 'critico')])
            ->orderBy('host')
            ->get();

        return [
            'sites' => $sites,
            'staleHours' => (int) config('secmon.security.stale_after_hours'),
            'card' => $this->card(),
        ];
    }

    /** @return array<string, mixed> */
    public function show(SecuritySite $site): array
    {
        $site->load('lastCheck');
        $last = $site->lastCheck;
        $previous = $last ? $site->checks()->where('started_at', '<', $last->started_at)->orderByDesc('started_at')->first() : null;

        return [
            'site' => $site,
            'last' => $last,
            'groups' => $last ? $this->groups($last) : [],
            'findings' => $site->findings()->where('status', 'open')->orderByRaw("case severity when 'critico' then 0 else 1 end")->orderBy('code')->get(),
            // Na primeira varredura não há com o que comparar: tudo seria "apareceu".
            'first' => $last !== null && $previous === null,
            // O que piorou NA ÚLTIMA varredura (os eventos dela são gravados depois do started_at dela;
            // os da anterior, antes): o que o admin precisa ler primeiro.
            'worsened' => $last && $previous
                ? $site->events()->whereIn('kind', ['opened', 'worsened', 'reopened'])->where('created_at', '>=', $last->started_at)->orderBy('created_at')->get()
                : collect(),
            'events' => $site->events()->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get(),
            'history' => $site->checks()->orderByDesc('started_at')->limit(15)->get(['id', 'site_id', 'started_at', 'status', 'score', 'error']),
            'staleHours' => (int) config('secmon.security.stale_after_hours'),
        ];
    }

    /**
     * O cartão do painel do admin: pior estado, quando foi a última varredura e se o agendador parou.
     *
     * @return array{sites: int, status: ?string, critical: int, lastScanAt: ?Carbon, stale: bool}
     */
    public function card(): array
    {
        $sites = SecuritySite::query()->where('enabled', true)->get();
        $stale = (int) config('secmon.security.stale_after_hours');

        $order = ['erro' => 3, 'critico' => 3, 'aviso' => 2, 'ok' => 1];
        $worst = $sites->pluck('last_status')->filter()->sortByDesc(fn ($s) => $order[$s] ?? 0)->first();

        return [
            'sites' => $sites->count(),
            'status' => $worst,
            'critical' => (int) SecurityFinding::query()->whereIn('site_id', $sites->pluck('id'))->where('status', 'open')->where('severity', 'critico')->count(),
            'lastScanAt' => $sites->pluck('last_check_at')->filter()->max(),
            // Sem varredura há mais de 48 h (ou nunca) em algum site ligado: o agendador pode ter parado.
            'stale' => $sites->contains(fn (SecuritySite $s) => $s->isStale($stale)),
        ];
    }

    /**
     * Os resultados da última varredura, no formato do catálogo e agrupados por área.
     *
     * @return array<string, array{label: string, rows: list<array<string, mixed>>}>
     */
    private function groups(SecurityCheck $check): array
    {
        $groups = [];
        $results = (array) $check->results;

        foreach (CheckCatalog::all() as $id => $info) {
            foreach ($results as $key => $result) {
                [$resultId, $subject] = array_pad(explode('|', (string) $key, 2), 2, '');

                if ($resultId !== $id) {
                    continue;
                }

                $groups[$info['group']]['label'] = CheckCatalog::GROUPS[$info['group']];
                $groups[$info['group']]['rows'][] = [
                    'id' => $id,
                    'label' => $info['label'],
                    'subject' => $subject,
                    'status' => $result['s'] ?? 'nao_testavel',
                    'value' => $result['v'] ?? '',
                    'advice' => in_array($result['s'] ?? null, ['aviso', 'critico'], true) ? $info['advice'] : null,
                ];
            }
        }

        // Na ordem das áreas do catálogo.
        return array_replace(array_intersect_key(array_fill_keys(array_keys(CheckCatalog::GROUPS), null), $groups), $groups);
    }

    /** @return Collection<int, SecurityEvent> */
    public function recentEvents(int $limit = 10): Collection
    {
        return SecurityEvent::query()->with('site:id,host')->orderByDesc('created_at')->limit($limit)->get();
    }
}
