<?php

namespace App\Jobs;

use App\Models\LookalikeWatch;
use App\Services\Lookalike\LookalikeScanner;
use App\Services\Lookalike\ScanRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Último job da varredura completa do Radar de domínios: junta os lotes de
 * DNS e os certificados, lê o registro e a hospedagem dos que existem e pede as
 * páginas ao navegador. Despachado quando o batch termina, com ou sem falhas.
 *
 * Não confia só na contagem do batch: um job que rodou em duplicata (worker com
 * `retry_after` curto) faz o batch terminar antes da hora. Se falta o resultado
 * de algum lote ou dos certificados, espera (até ~10 min) em vez de juntar pela metade.
 */
class FinishLookalikeScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** Quantas vezes já esperou por peças que faltam, de 15 s em 15 s. */
    public const MAX_WAITS = 40;

    public function __construct(public int $watchId, public string $runId, public int $waits = 0)
    {
        $this->onQueue('lookalikes');
    }

    public function handle(LookalikeScanner $scanner): void
    {
        $watch = LookalikeWatch::query()->find($this->watchId);

        if (! $watch || ! $watch->isActive()) {
            ScanRun::end($this->watchId, $this->runId, (int) (ScanRun::get($this->runId, 'meta')['chunks'] ?? 0));

            return;
        }

        if ($this->waits < self::MAX_WAITS && ScanRun::missing($this->runId) > 0) {
            self::dispatch($this->watchId, $this->runId, $this->waits + 1)->delay(now()->addSeconds(15));

            return;
        }

        $scanner->complete($watch, $this->runId);
    }

    /** Morreu no meio (tempo esgotado, worker caiu): solta a trava para a próxima rodada não esperar horas. */
    public function failed(?Throwable $e): void
    {
        ScanRun::end($this->watchId, $this->runId, (int) (ScanRun::get($this->runId, 'meta')['chunks'] ?? 0));
        LookalikeWatch::query()->whereKey($this->watchId)->where('scan_status', 'running')->update([
            'scan_status' => 'failed',
            'last_error' => 'A varredura parou no meio ('.mb_substr((string) $e?->getMessage(), 0, 200).'); ela recomeça na próxima rodada.',
        ]);
    }
}
