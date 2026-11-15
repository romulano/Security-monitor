<?php

namespace App\Jobs;

use App\Models\OwnedDomain;
use App\Services\Domains\SubdomainDiscovery;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A descoberta passiva dos subdomínios de um domínio da empresa (E3c, SubdomainDiscovery): um job por domínio de cada
 * vez, na fila `subdomains`, a última do worker de baixa prioridade (não atrasa alertas nem envios). O serviço relê
 * tudo do banco e para no tempo dele, antes do worker matar o job.
 */
class DiscoverSubdomains implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 150;

    public int $uniqueFor = 3600;

    public function __construct(public int $ownedDomainId)
    {
        $this->onQueue('subdomains');
    }

    public function uniqueId(): string
    {
        return (string) $this->ownedDomainId;
    }

    public function handle(SubdomainDiscovery $discovery): void
    {
        if ($domain = OwnedDomain::query()->find($this->ownedDomainId)) {
            $discovery->run($domain);
        }
    }
}
