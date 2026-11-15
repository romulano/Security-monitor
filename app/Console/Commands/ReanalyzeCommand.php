<?php

namespace App\Console\Commands;

use App\Jobs\CollectAnalysisBatch;
use App\Jobs\EnrichArticle;
use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Crawler\CaseRebuilder;
use App\Services\Enrichment\AnalysisBatch;
use App\Services\Enrichment\ClaudeEnricher;
use App\Services\Search\CaseIndex;
use App\Services\Sitemap;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Refaz a análise dos artigos já coletados com o driver ativo.
 *
 * É o comando para rodar depois de configurar a chave da IA (as notícias
 * coletadas antes disso ficaram com a extração heurística) ou depois de mexer
 * no limiar de deduplicação. O conteúdo baixado é reaproveitado — nada é
 * buscado de novo na internet.
 */
class ReanalyzeCommand extends Command
{
    protected $signature = 'secmon:reanalyze
        {--source= : limita a uma fonte (id ou host)}
        {--case=* : só estes casos (uuid), refeitos no lugar — o link continua o mesmo}
        {--sync : processa na hora, sem fila}
        {--batch : pelo lote da API (metade do preço, resposta em minutos ou horas; só com o driver claude)}
        {--force : não pede confirmação (nem para refazer do zero a matéria grande)}';

    protected $description = 'Reconstrói os casos já coletados com o driver de IA atual';

    public function handle(CaseIndex $index, CaseRebuilder $rebuilder, Sitemap $sitemap, AnalysisBatch $batches): int
    {
        $driver = (string) Settings::get('enricher.driver');

        if ($this->option('batch') && ($problem = $this->batchProblem($driver, $batches))) {
            $this->error($problem);

            return self::FAILURE;
        }

        if ($uuids = $this->option('case')) {
            return $this->inPlace($uuids, $driver, $rebuilder);
        }

        $articles = Article::query()
            ->whereNotNull('case_id')
            ->when($this->option('source'), function ($q, $needle) {
                is_numeric($needle)
                    ? $q->where('source_id', (int) $needle)
                    : $q->where('source_host', $needle);
            })
            ->pluck('id');

        if ($articles->isEmpty()) {
            $this->line('Nenhum artigo local para reanalisar.');

            return self::SUCCESS;
        }

        $this->warn("{$articles->count()} artigos serão reanalisados com o driver '{$driver}'".($this->option('batch') ? ', pelo lote da API.' : '.'));

        if (! $this->option('force') && ! $this->confirm('Continuar?', true)) {
            return self::SUCCESS;
        }

        // Solta os artigos antes de apagar os casos: a FK é cascade e levaria o
        // conteúdo já baixado junto.
        Article::query()->whereIn('id', $articles)->update([
            'case_id' => null,
            'status' => 'pending',
            'attempts' => 0,
            'is_first' => false,
            'is_new_info' => true,
            'new_context' => null,
            'error' => null,
        ]);

        // Só apaga casos que ficaram sem nenhum artigo.
        $orphans = NewsCase::query()->whereDoesntHave('articles')->pluck('uuid');

        NewsCase::query()->whereIn('uuid', $orphans)->delete();
        // O lote apaga sem passar pelo modelo (o PublicCaseUrls não vê): o sitemap é refeito daqui.
        $sitemap->forget();

        foreach ($orphans as $uuid) {
            $index->forget($uuid);
        }

        // Pelo lote: os artigos ficam reservados para ele, e o coletor envia as rodadas.
        if ($this->option('batch')) {
            try {
                $run = $batches->start($articles);
            } catch (Throwable $e) {
                // Os artigos já estão soltos: seguem pela análise normal, como sem o --batch.
                $this->error('O lote da API não aceitou a rodada ('.$e->getMessage().'): os artigos vão pela análise normal.');

                foreach ($articles as $id) {
                    EnrichArticle::dispatch($id, 'refazer-materia')->onQueue('enrich');
                }

                return self::FAILURE;
            }

            $index->refresh();

            $this->newLine();
            $this->info('Rodada 1 enviada ao lote da API ('.count($run['sent'] ?? []).' artigos; '.count($run['pending'] ?? []).' esperam a rodada seguinte, um por matéria).');
            $this->line('O coletor passa a cada '.CollectAnalysisBatch::EVERY_MINUTES.' minutos; acompanhe no log ("Lote da API").');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($articles->count());

        foreach ($articles as $id) {
            $this->option('sync')
                ? EnrichArticle::dispatchSync($id, 'refazer-materia')
                : EnrichArticle::dispatch($id, 'refazer-materia')->onQueue('enrich');

            $bar->advance();
        }

        $bar->finish();
        $index->refresh();

        $this->newLine(2);
        $this->info($this->option('sync')
            ? "{$articles->count()} artigos reanalisados."
            : "{$articles->count()} artigos enfileirados — acompanhe em Admin → Execuções.");

        return self::SUCCESS;
    }

    /** Por que o --batch não roda agora, ou nulo. */
    private function batchProblem(string $driver, AnalysisBatch $batches): ?string
    {
        return match (true) {
            $driver !== 'claude' => "O --batch usa o lote da API da Anthropic: só com o driver 'claude' (o atual é '{$driver}').",
            ! (new ClaudeEnricher)->isReady() => 'O --batch precisa da ANTHROPIC_API_KEY.',
            (bool) $this->option('sync') => 'O --batch não roda com --sync: o lote responde em minutos ou horas.',
            (bool) $this->option('case') => 'O --batch não refaz matérias no lugar (--case): elas vão artigo por artigo, na hora.',
            $batches->active() !== null => 'Já há um reprocessamento pelo lote em andamento; espere ele terminar.',
            default => null,
        };
    }

    /**
     * Refaz casos específicos sem desmontá-los (ver CaseRebuilder), sempre do
     * zero: é o caminho para refazer por completo a matéria grande, que o
     * RebuildCase só atualiza com o artigo que pediu. Roda na hora, sem fila.
     */
    private function inPlace(array $uuids, string $driver, CaseRebuilder $rebuilder): int
    {
        $this->warn(count($uuids)." caso(s) serão refeitos no lugar com o driver '{$driver}'.");

        foreach ($uuids as $uuid) {
            $case = Str::isUuid($uuid) ? NewsCase::query()->where('uuid', $uuid)->first() : null;

            if (! $case) {
                $this->error("Caso {$uuid} não existe.");

                continue;
            }

            if (! $rebuilder->fullRebuild($case) && ! $this->option('force')) {
                $total = $case->articles()->count();
                $minutes = $total * (int) config('secmon.rebuild.minutes_per_article', 2);

                if (! $this->confirm("{$uuid}: {$total} artigos, ~{$minutes} min de IA. Refazer do zero?", false)) {
                    continue;
                }
            }

            foreach ($rebuilder->rebuild($case) as $step) {
                $this->line("  {$uuid} · artigo #{$step['article_id']} via {$step['driver']}"
                    .($step['bilingual'] ? ' · EN + PT-BR' : ' · só EN')
                    .($step['split'] ? ' · outro assunto, virou caso novo' : '')
                    .($step['error'] ? " · {$step['error']}" : ''));
            }
        }

        return self::SUCCESS;
    }
}
