<?php

namespace App\Jobs;

use App\Services\Lookalike\LookalikeScanner;
use App\Services\Lookalike\ScanRun;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Um lote de nomes do Radar de domínios conferido no DNS (uns 100, uns 15 s).
 * Faz parte do batch de uma varredura completa; tem retry próprio, e um lote
 * que esgota as tentativas não derruba os outros: o último job junta o que
 * houver e o resto volta na próxima varredura.
 */
class CheckLookalikeNames implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 180;

    /** @param  list<array{0: string, 1: ?string}>  $candidates  [host, técnica] */
    public function __construct(public int $watchId, public string $runId, public int $index, public array $candidates)
    {
        $this->onQueue('lookalikes');
    }

    public function tries(): int
    {
        return max(1, (int) config('secmon.lookalikes.dns_batch_tries', 3));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_map('intval', (array) config('secmon.lookalikes.dns_batch_backoff', [30, 120]));
    }

    public function handle(LookalikeScanner $scanner): void
    {
        $scanner->checkNames($this->runId, $this->index, $this->candidates, final: $this->attempts() >= $this->tries());
    }

    /** Esgotou as tentativas: deixa o aviso de falha para o último job não esperar por este lote. */
    public function failed(?Throwable $e): void
    {
        ScanRun::put($this->runId, "chunk.{$this->index}", ['failed' => true]);
    }
}
