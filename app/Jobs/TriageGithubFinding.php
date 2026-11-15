<?php

namespace App\Jobs;

use App\Models\GithubFinding;
use App\Services\Github\FindingFlow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Etapa 1 de um achado do GitHub: a classificação por nível. O que é achado,
 * no nível escolhido pelo cliente, segue para o parecer de envio
 * (ReviewGithubFinding). Fila `judge`, como os outros pareceres.
 */
class TriageGithubFinding implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $findingId)
    {
        $this->onQueue('judge');
    }

    public function handle(FindingFlow $flow): void
    {
        $finding = GithubFinding::query()->with('monitor')->find($this->findingId);

        // Job repetido: o que já foi classificado não volta.
        if ($finding && $finding->status === 'triage') {
            $flow->triage($finding);
        }
    }
}
