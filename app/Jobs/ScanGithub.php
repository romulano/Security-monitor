<?php

namespace App\Jobs;

use App\Models\GithubMonitor;
use App\Services\Github\ExtraFlow;
use App\Services\Github\GithubScanner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Varredura diária do monitoramento do GitHub de um cliente. A busca em
 * código aceita 10 por minuto: com 30 termos, leva uns 3 minutos esperando
 * a janela da API. Antes, lê o texto "Adicional" se ele ainda não foi lido.
 */
class ScanGithub implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 1200;

    public function __construct(public int $monitorId)
    {
        $this->onQueue('github');
    }

    public function uniqueId(): string
    {
        return (string) $this->monitorId;
    }

    public function handle(GithubScanner $scanner, ExtraFlow $extra): void
    {
        if (! config('secmon.github.enabled')) {
            return;
        }

        $monitor = GithubMonitor::query()->find($this->monitorId);

        if (! $monitor || ! $monitor->isActive()) {
            return;
        }

        if (in_array($monitor->extra_status, ['pending', 'failed'], true)) {
            $extra->interpret($monitor);
        }

        try {
            $scanner->scan($monitor);
        } catch (Throwable $e) {
            Log::warning('Varredura do GitHub falhou', ['monitor' => $monitor->id, 'error' => $e->getMessage()]);
            $monitor->forceFill([
                'scan_status' => 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 500),
                // Falhou: tenta de novo em 6 h, não espera o dia seguinte.
                'next_scan_at' => now()->addHours(6),
            ])->save();
        }
    }
}
