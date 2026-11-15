<?php

namespace App\Console\Commands;

use App\Jobs\DiscoverSubdomains;
use App\Models\OwnedDomain;
use App\Services\Company\Companies;
use Illuminate\Console\Command;

/**
 * A descoberta passiva de subdomínios no agendador (E3c), de hora em hora: os domínios comprovados das empresas ativas
 * cuja última descoberta passou de `every_hours`, os mais atrasados primeiro, até `per_run` por rodada (espalha o crt.sh
 * pelo dia). Só enfileira; o job confere tudo de novo. `--domain=` roda um só, na hora (laboratório, suporte).
 */
class SubdomainsDiscoverCommand extends Command
{
    protected $signature = 'secmon:subdomains-discover {--due : os domínios que venceram} {--domain= : o id de um domínio, na hora}';

    protected $description = 'Enfileira a descoberta passiva de subdomínios (certificados públicos e DNS) dos domínios da empresa';

    public function handle(Companies $companies): int
    {
        if (! config('secmon.subdomains.discovery_enabled') || ! $companies->enabled()) {
            $this->info('Descoberta de subdomínios desligada.');

            return self::SUCCESS;
        }

        if ($id = $this->option('domain')) {
            DiscoverSubdomains::dispatchSync((int) $id);
            $this->info('Descoberta feita.');

            return self::SUCCESS;
        }

        if (! $this->option('due')) {
            $this->error('Use --due ou --domain=<id>.');

            return self::FAILURE;
        }

        $due = OwnedDomain::query()
            ->whereNotNull('company_id')
            ->whereNotNull('verified_at')
            ->whereNull('unseen_since')
            ->whereHas('company', fn ($q) => $q->whereNotNull('enterprise_enabled_at')->whereNull('suspended_at'))
            ->where(fn ($q) => $q->whereNull('subdomains_checked_at')->orWhere('subdomains_checked_at', '<', now()->subHours((int) config('secmon.subdomains.every_hours'))))
            ->orderByRaw('subdomains_checked_at asc nulls first')
            ->orderBy('id')
            ->limit((int) config('secmon.subdomains.per_run'))
            ->pluck('id');

        $due->each(fn (int $id) => DiscoverSubdomains::dispatch($id));
        $this->info("{$due->count()} domínio(s) na fila `subdomains`.");

        return self::SUCCESS;
    }
}
