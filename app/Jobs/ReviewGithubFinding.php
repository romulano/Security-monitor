<?php

namespace App\Jobs;

use App\Models\GithubFinding;
use App\Services\Github\FindingFlow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Etapa 2 de um achado do GitHub: o parecer de envio, que só roda depois de
 * a classificação dizer que é achado no nível do cliente. Acima do corte o
 * achado sai para os apps; no corte ou abaixo, fica "segurado pela IA".
 */
class ReviewGithubFinding implements ShouldQueue
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
        $finding = GithubFinding::query()->with(['monitor.user.notificationChannels'])->find($this->findingId);

        if ($finding && $finding->status === 'ai_review') {
            $flow->review($finding);
        }
    }
}
