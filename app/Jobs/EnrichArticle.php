<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Crawler\AnalysisAttempts;
use App\Services\Crawler\CaseResolver;
use App\Services\Crawler\CaseWriter;
use App\Services\Enrichment\AnalysisBatch;
use App\Services\Enrichment\ClaudeEnricher;
use App\Services\Enrichment\EnricherManager;
use App\Services\Review\ReportReviewer;
use App\Services\Review\ReviewLoop;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Analisa um artigo coletado e o encaixa num caso — novo ou existente.
 */
class EnrichArticle implements ShouldQueue
{
    use Queueable;

    // Duas falhas de verdade encerram; esperar a vez no caso (release) não conta.
    public int $maxExceptions = 2;

    // Com o revisor do relatório ligado, o job ganha o tempo das rodadas dele.
    public int $timeout = 300;

    /**
     * O que pediu a análise (ArticleAttempt::TRIGGERS), para o histórico. Fora do
     * construtor para ter padrão no job serializado antes dele.
     */
    public string $trigger = 'coleta';

    public function __construct(public int $articleId, string $trigger = 'coleta')
    {
        $this->timeout = 300 + ReportReviewer::extraSeconds();
        $this->trigger = $trigger;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(
        EnricherManager $enrichers,
        CaseResolver $resolver,
        CaseWriter $writer,
        ?ReviewLoop $reviews = null,
    ): void {
        $started = microtime(true);
        $reviews ??= app(ReviewLoop::class);
        $article = Article::find($this->articleId);

        // O mesmo artigo pode estar na fila mais de uma vez (o reprocessamento
        // horário reenfileira o que ainda está pendente). Repetição também já
        // está resolvida: analisar de novo gastaria IA e contaria duas vezes.
        if (! $article || in_array($article->status, ['enriched', 'no_update'], true)) {
            return;
        }

        $existing = self::existingCase($article, $resolver);
        $lock = $existing ? NewsCase::reportLock($existing->id, $this->timeout + 30) : null;

        // Outro artigo está enriquecendo o relatório deste caso. A IA tem de ler
        // o relatório já com ele: na fila, o artigo volta daqui a pouco; rodando
        // direto (comando com --sync), espera a vez.
        if ($lock && ! $lock->get()) {
            if ($this->job && $this->job->getConnectionName() !== 'sync') {
                $this->release(60);

                return;
            }

            $lock->block($this->timeout + 30);
        }

        try {
            // A fusão de matérias pode ter apagado o caso enquanto o artigo esperava:
            // o resolvedor acha a matéria nova pelo redirecionamento.
            $this->analyze($article, $existing ? ($existing->fresh() ?? self::existingCase($article, $resolver)) : null, $enrichers, $writer, $reviews, $started);
        } finally {
            $lock?->release();
        }
    }

    /**
     * A matéria que o artigo atualizaria: CVEs crus servem de âncora para
     * achá-la antes mesmo da análise. O lote da API (AnalysisBatch) usa o
     * mesmo critério para saber em que matéria cada artigo cai.
     */
    public static function existingCase(Article $article, CaseResolver $resolver): ?NewsCase
    {
        preg_match_all('/\bCVE-\d{4}-\d{4,7}\b/i', (string) ($article->original_title.' '.$article->content), $m);

        return $resolver->resolve($article, array_values(array_unique(array_map('strtoupper', $m[0] ?? []))));
    }

    /**
     * A resposta que o lote da API deixou para este artigo, se ainda serve: a
     * matéria tem de ser a mesma, com o mesmo relatório que a análise leu. Não
     * servindo, ou não dando para ler, vale a análise normal.
     *
     * @return ?array{EnrichmentResult, null}
     */
    private function fromBatch(Article $article, ?NewsCase $existing): ?array
    {
        $batch = AnalysisBatch::take($article->id);

        if (! $batch) {
            return null;
        }

        if ($existing?->id !== $batch['case'] || AnalysisBatch::stamp($existing) !== $batch['stamp']) {
            Log::info('Lote da API: a matéria mudou desde o envio; análise normal', ['article_id' => $article->id]);

            return null;
        }

        try {
            return [(new ClaudeEnricher)->fromText($batch['text'], $article, $existing), null];
        } catch (Throwable $e) {
            Log::warning('Lote da API: resposta ilegível; análise normal', ['article_id' => $article->id, 'erro' => $e->getMessage()]);

            return null;
        }
    }

    private function analyze(Article $article, ?NewsCase $existing, EnricherManager $enrichers, CaseWriter $writer, ReviewLoop $reviews, float $started): void
    {
        $article->increment('attempts');
        $history = app(AnalysisAttempts::class);

        try {
            [$result, $driverError] = $this->fromBatch($article, $existing) ?? $enrichers->enrich($article, $existing);
        } catch (Throwable $e) {
            $article->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            $history->record($article, $this->trigger, null, $e->getMessage());

            throw $e;
        }

        // O agrupamento é só por título e CVE; a IA leu os dois textos. Quando
        // ela diz que é outro assunto (ex.: outra edição de um resumo semanal),
        // o artigo vira caso próprio em vez de "atualização" do caso errado.
        if ($existing && ! $result->sameCase) {
            Log::info('IA separou o artigo do caso agrupado', [
                'article_id' => $article->id,
                'case_id' => $existing->id,
            ]);

            $existing = null;
        }

        // O revisor confere o rascunho antes de ele ir ao ar, até o limite do job.
        [$result, $review] = $reviews->run($article, $existing, $result, $driverError, $started + $this->timeout - 30);

        if ($existing) {
            if ($result->isNewInfo) {
                $case = $writer->appendUpdate($existing, $article, $result);
            } else {
                $writer->markNoUpdate($existing, $article, $result);
                $case = $existing;
            }
        } else {
            $case = $writer->createCase($article, $result);
        }

        $review?->record($case, $article);

        if ($driverError) {
            $article->forceFill(['error' => mb_substr($driverError, 0, 1000)])->save();
            Log::notice('Artigo enriquecido com fallback', ['article_id' => $article->id, 'motivo' => $driverError]);
        }

        $history->record($article, $this->trigger, $result, $driverError);
    }
}
