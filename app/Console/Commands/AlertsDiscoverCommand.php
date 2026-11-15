<?php

namespace App\Console\Commands;

use App\Jobs\DiscoverDomainStack;
use App\Models\MonitoredDomain;
use App\Models\TechCatalog;
use App\Services\Alerts\Discovery\DiscoveryService;
use App\Support\DomainName;
use App\Support\Settings;
use Illuminate\Console\Command;

class AlertsDiscoverCommand extends Command
{
    protected $signature = 'secmon:alerts-discover
        {domain? : roda agora a descoberta deste domínio cadastrado (respeita 1 varredura por mês)}
        {--force : operador, para teste: varre mesmo dentro do mês}
        {--due : enfileira os domínios cuja próxima execução venceu (agendado, diário)}
        {--probe= : diagnóstico de operador: roda as checagens num host e mostra o resultado, sem gravar nada}';

    protected $description = 'Descoberta passiva das tecnologias dos domínios dos clientes';

    public function handle(DiscoveryService $discovery): int
    {
        if ($host = $this->option('probe')) {
            return $this->probe($discovery, (string) $host);
        }

        if ($name = $this->argument('domain')) {
            $domain = MonitoredDomain::query()->ofAlerts()->where('domain', DomainName::normalize($name))->first();

            if (! $domain || ! $domain->canDiscover()) {
                $this->error('Domínio não cadastrado, sem aceite ou sem posse comprovada.');

                return self::FAILURE;
            }

            if (! $discovery->run($domain, force: (bool) $this->option('force'))) {
                $next = $domain->refresh()->nextScanAt();
                $this->warn("{$domain->domain}: varrido em ".$domain->last_scan_at?->timezone('America/Sao_Paulo')->format('d/m')
                    .($next ? '; a próxima varredura fica liberada em '.$next->timezone('America/Sao_Paulo')->format('d/m') : '')
                    .' (1 por mês). Para testar mesmo assim: --force.');

                return self::SUCCESS;
            }

            $domain->refresh();
            $this->info("{$domain->domain}: {$domain->discovery_status}, {$domain->findings()->count()} achado(s).");

            return self::SUCCESS;
        }

        if ($this->option('due')) {
            if (! Settings::bool('alerts.enabled') || ! Settings::bool('alerts.discovery_enabled')) {
                $this->line('Descoberta desligada no admin.');

                return self::SUCCESS;
            }

            $count = 0;

            MonitoredDomain::query()
                ->ofAlerts()
                ->whereNotNull('verified_at')
                ->whereNotNull('consent_at')
                ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('last_scan_at')->orWhere('last_scan_at', '<=', now()->subDays(MonitoredDomain::scanIntervalDays())))
                ->each(function (MonitoredDomain $domain) use (&$count) {
                    DiscoverDomainStack::dispatch($domain->id);
                    $count++;
                });

            $this->info("{$count} domínio(s) enfileirado(s).");

            return self::SUCCESS;
        }

        $this->error('Informe um domínio, --due ou --probe=host.');

        return self::INVALID;
    }

    private function probe(DiscoveryService $discovery, string $host): int
    {
        $host = DomainName::normalize($host);

        if (! $host) {
            $this->error('Host inválido.');

            return self::INVALID;
        }

        $result = $discovery->probe($host);

        foreach ($result['log'] as $line) {
            $this->line(sprintf('  [%s] %s', $line['level'], $line['msg']));
        }

        $this->newLine();
        $this->info('Reconhecidas pelo catálogo');
        $this->table(['Tecnologia', 'Evidência', 'Confiança'], array_map(fn ($f) => [$f['label'], $f['evidence'], $f['confidence']], $result['findings']));

        $this->info('Fora do catálogo (num domínio de cliente, seriam cadastradas para monitoria)');
        $this->table(['Item', 'Categoria', 'Termos de busca', 'Evidência'], array_map(fn ($c) => [
            $c['name'], TechCatalog::CATEGORIES[$c['category']] ?? $c['category'], implode(', ', $c['terms']), $c['evidence'],
        ], $result['new']));

        foreach ($result['hints'] as $hint) {
            $this->warn(is_array($hint) ? 'Bloqueio: '.$hint['texto'] : 'Dica: '.$hint);
        }

        return self::SUCCESS;
    }
}
