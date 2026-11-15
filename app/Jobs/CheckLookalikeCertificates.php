<?php

namespace App\Jobs;

use App\Services\Lookalike\LookalikeScanner;
use App\Services\Lookalike\ScanRun;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Busca em certificados públicos (crt.sh) com o nome da marca, dentro do batch
 * da varredura completa, em paralelo com os lotes de DNS. O crt.sh cai com
 * frequência (502): o job tenta de novo antes de desistir e, na última vez,
 * guarda o erro, que o cliente vê no log da varredura.
 */
class CheckLookalikeCertificates implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 180;

    /** @param  list<string>  $ownDomains */
    public function __construct(public int $watchId, public string $runId, public string $label, public array $ownDomains)
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
        $scanner->checkCertificates($this->runId, $this->label, $this->ownDomains, final: $this->attempts() >= $this->tries());
    }

    /** Esgotou as tentativas: deixa o aviso de falha para o último job não esperar por esta busca. */
    public function failed(?Throwable $e): void
    {
        ScanRun::put($this->runId, 'certs', ['hosts' => [], 'found' => [], 'error' => 'a busca em certificados falhou']);
    }
}
