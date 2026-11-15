<?php

namespace App\Console\Commands;

use App\Jobs\EnrichArticle;
use App\Models\Article;
use App\Services\Enrichment\AnalysisBatch;
use Illuminate\Console\Command;

class EnrichPendingCommand extends Command
{
    protected $signature = 'secmon:enrich-pending
        {--limit=200 : máximo de artigos reenfileirados}
        {--sync : processa na hora, sem fila}';

    protected $description = 'Reenfileira artigos coletados que ainda não viraram matéria';

    public function handle(): int
    {
        $articles = Article::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', 3)
            // Os que o reprocessamento pelo lote da API ainda vai analisar (a preço de lote).
            ->whereNotIn('id', app(AnalysisBatch::class)->reserved())
            ->orderBy('collected_at')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        if ($articles->isEmpty()) {
            $this->line('Nada pendente.');

            return self::SUCCESS;
        }

        foreach ($articles as $id) {
            if ($this->option('sync')) {
                EnrichArticle::dispatchSync($id);
            } else {
                EnrichArticle::dispatch($id)->onQueue('enrich');
            }
        }

        $this->info($articles->count().' artigos '.($this->option('sync') ? 'processados' : 'reenfileirados').'.');

        return self::SUCCESS;
    }
}
