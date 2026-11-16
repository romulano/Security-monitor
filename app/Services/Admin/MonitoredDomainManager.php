<?php

namespace App\Services\Admin;

use App\Exceptions\ActionRefused;
use App\Jobs\DiscoverDomainStack;
use App\Models\MonitoredDomain;

/** Admin → Alertas → Domínios: os sites dos clientes, o log da descoberta e a varredura pedida à mão. */
class MonitoredDomainManager
{
    /** @param  ?int  $logId  o domínio cujo log da última execução abre na página */
    public function page(?int $logId): array
    {
        // Só os domínios dos alertas de notícias: os do Radar não passam pela descoberta.
        $domains = MonitoredDomain::query()
            ->ofAlerts()
            ->with(['profile.user', 'findings'])
            ->orderByRaw("case discovery_status when 'blocked' then 0 when 'failed' then 1 else 2 end")
            ->latest('updated_at')
            ->paginate(30);

        return [
            'domains' => $domains,
            'log' => $logId ? MonitoredDomain::query()->ofAlerts()->with(['findings', 'profile.user'])->find($logId) : null,
            'counts' => [
                'total' => MonitoredDomain::query()->ofAlerts()->count(),
                'verified' => MonitoredDomain::query()->ofAlerts()->whereNotNull('verified_at')->count(),
                'blocked' => MonitoredDomain::query()->ofAlerts()->where('discovery_status', 'blocked')->count(),
            ],
        ];
    }

    /** Enfileira a descoberta: só com aceite e posse comprovada, e no máximo 1 varredura por mês. */
    public function runDiscovery(MonitoredDomain $domain): void
    {
        if (! $domain->canDiscover()) {
            throw new ActionRefused('Sem aceite ou sem posse comprovada: a descoberta não pode rodar nesse domínio.');
        }

        if ($next = $domain->nextScanAt()) {
            throw new ActionRefused("{$domain->domain} foi varrido em ".$domain->last_scan_at->timezone('America/Sao_Paulo')->format('d/m')
                .'. A próxima varredura fica liberada em '.$next->timezone('America/Sao_Paulo')->format('d/m').' (no máximo 1 por mês, para não sobrecarregar o site do cliente).');
        }

        DiscoverDomainStack::dispatch($domain->id);
    }
}
