<?php

namespace App\Console\Commands;

use App\Services\Maintenance\RetentionPruner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneCommand extends Command
{
    protected $signature = 'secmon:prune {--dry-run : só conta o que seria apagado}';

    protected $description = 'Apaga o que passou do prazo de retenção: sessões, execuções do crawler, jobs que falharam, entregas e prints do Radar';

    public function handle(RetentionPruner $pruner): int
    {
        $dry = (bool) $this->option('dry-run');
        $counts = $pruner->run($dry);

        $this->table([$dry ? 'O que seria apagado' : 'O que foi apagado', 'Linhas'], collect($counts)->map(fn ($n, $what) => [$what, $n])->values()->all());

        if (! $dry) {
            Log::info('secmon:prune', $counts);
        }

        return self::SUCCESS;
    }
}
