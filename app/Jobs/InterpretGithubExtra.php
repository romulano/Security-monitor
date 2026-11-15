<?php

namespace App\Jobs;

use App\Models\GithubMonitor;
use App\Services\Github\ExtraFlow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A IA lê o texto "Adicional" da entrevista do GitHub (só quando há texto novo). Fila `judge`, onde ficam as vagas do hermes. */
class InterpretGithubExtra implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(public int $monitorId)
    {
        $this->onQueue('judge');
    }

    public function handle(ExtraFlow $extra): void
    {
        $monitor = GithubMonitor::query()->find($this->monitorId);

        if ($monitor && $monitor->extra_status === 'pending') {
            $extra->interpret($monitor);
        }
    }
}
