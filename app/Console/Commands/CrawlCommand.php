<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\Crawler\CrawlerService;
use Illuminate\Console\Command;

class CrawlCommand extends Command
{
    protected $signature = 'secmon:crawl
        {--source= : id ou host de uma fonte específica}
        {--all : ignora o intervalo e roda todas as fontes habilitadas}
        {--trigger=cli}';

    protected $description = 'Roda os bots de coleta das fontes de notícia';

    public function handle(CrawlerService $crawler): int
    {
        $trigger = (string) $this->option('trigger');

        if ($needle = $this->option('source')) {
            $source = Source::query()
                ->when(is_numeric($needle), fn ($q) => $q->where('id', (int) $needle))
                ->when(! is_numeric($needle), fn ($q) => $q->where('host', $needle))
                ->first();

            if (! $source) {
                $this->error("Fonte '{$needle}' não encontrada.");

                return self::FAILURE;
            }

            $this->report([$crawler->runSource($source, $trigger)]);

            return self::SUCCESS;
        }

        if ($this->option('all')) {
            $runs = Source::query()->where('enabled', true)->get()
                ->map(fn (Source $s) => $crawler->runSource($s, $trigger))
                ->all();
        } else {
            $runs = $crawler->runDue($trigger);
        }

        if ($runs === []) {
            $this->line('Nenhuma fonte no horário (ou crawler desligado no admin).');

            return self::SUCCESS;
        }

        $this->report($runs);

        return self::SUCCESS;
    }

    private function report(array $runs): void
    {
        $this->table(
            ['Fonte', 'Status', 'Itens', 'Novos', 'Duplicados', 'Falhas'],
            collect($runs)->map(fn ($run) => [
                $run->source?->host ?? '—',
                $run->status,
                $run->items_found,
                $run->items_new,
                $run->items_duplicate,
                $run->items_failed,
            ])->all()
        );
    }
}
