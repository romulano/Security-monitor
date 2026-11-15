<?php

namespace App\Services\Crawler;

use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Enrichment\EnricherManager;
use App\Services\Review\ReviewLoop;
use Throwable;

/**
 * Refaz um caso no lugar com o driver de IA atual: o primeiro artigo reescreve
 * o caso (mesmo uuid, mesmo link) e os seguintes entram como atualização ou
 * repetição, na ordem em que foram coletados. Usa o conteúdo já gravado —
 * nada é buscado de novo na internet.
 */
class CaseRebuilder
{
    public function __construct(
        private EnricherManager $enrichers,
        private CaseWriter $writer,
        private ReviewLoop $reviews,
        private AnalysisAttempts $attempts,
    ) {}

    /**
     * @return list<array{article_id: int, driver: string, bilingual: bool, split: bool, error: ?string}>
     */
    public function rebuild(NewsCase $case, string $trigger = 'refazer-materia'): array
    {
        $report = [];

        foreach ($this->articleIds($case) as $i => $id) {
            $report[] = $this->rebuildArticle($case, Article::query()->findOrFail($id), $i === 0, trigger: $trigger);
        }

        return $report;
    }

    /**
     * Os artigos do caso na ordem em que foram coletados. A lista é fixada no
     * começo: o artigo que a IA separa sai do caso, mas continua na fila.
     *
     * @return list<int>
     */
    public function articleIds(NewsCase $case): array
    {
        return $case->articles()->orderBy('collected_at')->orderBy('id')->pluck('id')->all();
    }

    /**
     * Refaz do zero só a matéria pequena. Na grande (mais de
     * `secmon.rebuild.full_max_articles` artigos), o relatório já reúne as fontes:
     * relê-las todas leva horas de IA para chegar ao mesmo lugar.
     */
    public function fullRebuild(NewsCase $case): bool
    {
        return $case->articles()->count() <= (int) config('secmon.rebuild.full_max_articles', 8);
    }

    /**
     * Na matéria grande, os artigos relidos contra a matéria atual: o que pediu o
     * refazer e os da matéria que ficaram sem a análise da IA, na ordem da coleta.
     *
     * @return list<int>
     */
    public function refreshIds(NewsCase $case, ?int $triggerId): array
    {
        return $case->articles()
            ->where(fn ($q) => $q->where('id', $triggerId)->orWhere(fn ($q) => $q->driverError()))
            ->orderBy('collected_at')->orderBy('id')->pluck('id')->all();
    }

    /**
     * Relê um artigo contra a matéria atual (com o known_report), sem reescrevê-la
     * do zero. O artigo que abriu a matéria continua o primeiro dela.
     *
     * @return array{article_id: int, driver: string, bilingual: bool, split: bool, error: ?string}
     */
    public function refreshArticle(NewsCase $case, Article $article, ?float $deadline = null, string $trigger = 'refazer-materia'): array
    {
        $wasFirst = $article->is_first;
        $step = $this->rebuildArticle($case, $article, false, $deadline, $trigger);

        if ($wasFirst && ! $step['split']) {
            $article->forceFill(['is_first' => true])->save();
        }

        return $step;
    }

    /**
     * Um passo do rebuild: o primeiro artigo reescreve o caso, os outros entram
     * como atualização ou repetição (ou saem, se a IA disser que é outro assunto).
     * Com o revisor ligado, o rascunho passa por ele antes de ir ao ar.
     *
     * @param  ?float  $deadline  até quando (microtime) o job pode chamar a IA
     * @return array{article_id: int, driver: string, bilingual: bool, split: bool, error: ?string}
     */
    public function rebuildArticle(NewsCase $case, Article $article, bool $first, ?float $deadline = null, string $trigger = 'refazer-materia'): array
    {
        try {
            [$result, $driverError] = $this->enrichers->enrich($article, $first ? null : $case->refresh());
        } catch (Throwable $e) {
            $this->attempts->record($article, $trigger, null, $e->getMessage());

            throw $e;
        }

        [$result, $review] = $this->reviews->run($article, $first ? null : $case, $result, $driverError, $deadline);

        // Mesmo critério do EnrichArticle: outro assunto sai do caso.
        $split = ! $first && ! $result->sameCase;

        $written = match (true) {
            $first => $this->writer->createCase($article, $result, $case),
            $split => $this->writer->createCase($article, $result),
            $result->isNewInfo => $this->writer->appendUpdate($case->refresh(), $article, $result),
            default => $this->writer->markNoUpdate($case->refresh(), $article, $result),
        };

        $review?->record($written ?? $case, $article, 'refazer');

        if ($driverError) {
            $article->forceFill(['error' => mb_substr($driverError, 0, 1000)])->save();
        }

        $this->attempts->record($article, $trigger, $result, $driverError);

        // Matéria da fusão de matérias: o relatório que juntava as fontes foi
        // trocado pela análise de uma fonte só. Na próxima noite a IA reescreve.
        if ($first && $case->consolidated_at) {
            $case->forceFill(['consolidated_at' => null])->save();
        }

        return [
            'article_id' => $article->id,
            'driver' => $result->driver,
            'bilingual' => filled($result->summaryPt),
            'split' => $split,
            'error' => $driverError,
        ];
    }
}
