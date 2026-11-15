<?php

namespace App\Console\Commands;

use App\Models\NewsCase;
use App\Services\Search\CaseIndex;
use Illuminate\Console\Command;

class ReindexCommand extends Command
{
    protected $signature = 'secmon:reindex {--fresh : recria o índice antes de reindexar}';

    protected $description = 'Reindexa todos os casos do PostgreSQL no Elasticsearch';

    public function handle(CaseIndex $index): int
    {
        if (! $index->ping()) {
            $this->error('Elasticsearch inacessível.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $index->drop();
        }

        $index->create();

        $total = NewsCase::query()->where('is_published', true)->count();
        $bar = $this->output->createProgressBar($total);
        $done = 0;

        NewsCase::query()
            ->where('is_published', true)
            ->with('articles:id,case_id,source_host')
            ->chunkById(200, function ($cases) use ($index, $bar, &$done) {
                $done += $index->bulk($cases);
                $bar->advance($cases->count());
            });

        $bar->finish();
        // As escondidas do feed público que ainda estavam na busca saem dela.
        $pruned = $index->pruneHidden();
        $index->refresh();

        $this->newLine(2);
        $this->info("{$done} casos indexados. Documentos no índice: {$index->count()}");

        if ($pruned) {
            $this->line("{$pruned} matéria(s) escondida(s) do feed público saíram da busca.");
        }

        return self::SUCCESS;
    }
}
