<?php

namespace App\Jobs;

use App\Services\Enrichment\AnalysisBatch;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O coletor do reprocessamento pelo lote da API (AnalysisBatch): passa de
 * tempos em tempos, lê o lote que terminou, espera os jobs da rodada gravarem
 * e envia a rodada seguinte, até a execução acabar. Uma passada por vez.
 */
class CollectAnalysisBatch implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const EVERY_MINUTES = 5;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function handle(AnalysisBatch $batches): void
    {
        try {
            $status = $batches->collect();
        } catch (Throwable $e) {
            // API fora do ar, lote não encontrado: tenta de novo na próxima passada.
            // A execução some sozinha do cache depois de 72 h.
            Log::warning('Lote da API: a coleta falhou; tenta de novo', ['erro' => $e->getMessage()]);
            $status = 'erro';
        }

        if ($status !== 'done') {
            self::dispatch()->delay(now()->addMinutes(self::EVERY_MINUTES));
        }
    }
}
