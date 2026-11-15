<?php

namespace App\Console\Commands;

use App\Jobs\ScanLookalikes;
use App\Models\LookalikeWatch;
use App\Services\Lookalike\LookalikeScanner;
use App\Services\Lookalike\ScanRun;
use App\Support\DomainName;
use Illuminate\Console\Command;

class LookalikesScanCommand extends Command
{
    protected $signature = 'secmon:lookalikes-scan
        {domain? : varre agora o Radar de domínios deste domínio verificado (liga o monitoramento se preciso)}
        {--recheck : só relê o DNS dos que já foram achados}
        {--due : agendado: varredura completa de quem venceu (todo dia às 01:00) e limpeza dos prints velhos}
        {--sync : roda aqui, sem fila}';

    protected $description = 'Radar de domínios: variações do nome e certificados conferidos no DNS, páginas pedidas ao navegador';

    public function handle(LookalikeScanner $scanner): int
    {
        if (! config('secmon.lookalikes.enabled')) {
            $this->warn('Radar de domínios desligado (LOOKALIKES_ENABLED).');

            return self::SUCCESS;
        }

        if ($this->option('due')) {
            $full = 0;

            // Roda a cada 15 minutos: varredura completa de quem venceu. Todo Radar vence às 01:00 de
            // Brasília (`scan_at`), e quem acabou de comprovar o domínio (ou foi retomado) vence na hora.
            foreach (LookalikeWatch::query()->where('status', 'active')->with('domain')->get() as $watch) {
                // Os lotes de uma varredura completa ainda andam: não começa outra por cima.
                if (! $watch->domain?->isVerified() || ScanRun::running($watch->id)) {
                    continue;
                }

                if (! $watch->next_scan_at || $watch->next_scan_at->isPast()) {
                    $this->dispatchScan($scanner, $watch, true);
                    $full++;
                }
            }

            // A poda dos prints velhos é do secmon:prune (diário), não mais de uma janela deste comando.
            $this->info("{$full} varredura(s) completa(s).");

            return self::SUCCESS;
        }

        $name = $this->argument('domain');

        if (! $name) {
            $this->error('Informe o domínio ou use --due.');

            return self::FAILURE;
        }

        $watch = LookalikeWatch::query()
            ->whereNotNull('completed_at')
            ->whereHas('domain', fn ($q) => $q->where('domain', DomainName::normalize($name)))
            ->with('domain')
            ->first();

        if (! $watch || ! $watch->domain->isVerified()) {
            $this->error('Domínio sem Radar ativado ou sem posse comprovada (TXT). O cliente integra o Radar em Alertas → Integração.');

            return self::FAILURE;
        }

        $watch = $scanner->enable($watch);
        $this->dispatchScan($scanner, $watch, ! $this->option('recheck'));

        if ($this->option('sync')) {
            foreach ($watch->refresh()->last_log ?? [] as $line) {
                $this->line('· '.$line['text']);
            }
        } else {
            $this->info('Varredura na fila `lookalikes`.');
        }

        return self::SUCCESS;
    }

    private function dispatchScan(LookalikeScanner $scanner, LookalikeWatch $watch, bool $full): void
    {
        if ($this->option('sync')) {
            $full ? $scanner->scan($watch) : $scanner->recheck($watch);

            return;
        }

        ScanLookalikes::dispatch($watch->id, $full);
    }
}
