<?php

namespace App\Jobs;

use App\Models\LookalikeWatch;
use App\Services\Lookalike\LookalikeScanner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Varredura do Radar de domínios de um domínio. A completa só prepara e
 * despacha os lotes de DNS (CheckLookalikeNames, CheckLookalikeCertificates e,
 * por fim, FinishLookalikeScan), todos na fila `lookalikes`; a `recheck` só
 * relê os que já existem.
 */
class ScanLookalikes implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public int $watchId, public bool $full = true)
    {
        $this->onQueue('lookalikes');
    }

    public function uniqueId(): string
    {
        return (string) $this->watchId;
    }

    public function handle(LookalikeScanner $scanner): void
    {
        if (! config('secmon.lookalikes.enabled')) {
            return;
        }

        $watch = LookalikeWatch::query()->find($this->watchId);

        if (! $watch || ! $watch->isActive()) {
            return;
        }

        $this->full ? $scanner->scanInBatches($watch) : $scanner->recheck($watch);
    }
}
