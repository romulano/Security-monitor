<?php

namespace App\Jobs;

use App\Models\MonitoredDomain;
use App\Services\Alerts\Discovery\DiscoveryService;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Descoberta passiva de um domínio. Um job por domínio de cada vez; a regra de
 * 1 varredura a cada 30 dias fica no serviço, então um job a mais não vira
 * requisição a mais. `retryWalls` relê só as páginas barradas.
 */
class DiscoverDomainStack implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 600;

    public function __construct(public int $domainId, public bool $retryWalls = false)
    {
        $this->onQueue('discovery');
    }

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }

    public function handle(DiscoveryService $discovery): void
    {
        if (! Settings::bool('alerts.enabled') || ! Settings::bool('alerts.discovery_enabled')) {
            return;
        }

        if (! $domain = MonitoredDomain::query()->find($this->domainId)) {
            return;
        }

        $this->retryWalls ? $discovery->retryWalls($domain) : $discovery->run($domain);
    }
}
