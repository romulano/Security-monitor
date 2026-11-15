<?php

namespace App\Services\Review;

use App\Models\Article;
use App\Models\NewsCase;
use App\Models\ReportReview;
use App\Services\Enrichment\ChatDriver;
use App\Services\Enrichment\EnricherManager;
use App\Services\Enrichment\EnrichmentResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * As rodadas do revisor sobre o rascunho da análise, antes de ele ir ao ar:
 * parecer → (ajuste: a análise refaz a parte ou tudo) → parecer…, com no
 * máximo `secmon.review.max_adjustments` ajustes. Depois do último, o parecer é
 * o final: reprovado, a matéria sai com a última versão e a revisão fica
 * pendente no admin.
 *
 * O parecer e a refação vão para a IA que escreveu o rascunho (o driver da
 * análise: hermes, OpenRouter…), não para uma fixa. O driver que não fala com o
 * revisor — a extração heurística — tem a revisão pulada e registrada.
 *
 * Roda dentro do job da análise (EnrichArticle, RebuildCase), com a trava do
 * relatório já tomada: cada chamada usa a vaga do worker, como a análise, e a
 * matéria nunca espera o revisor mais que o tempo do job. Qualquer falha (IA
 * fora, limite de uso, resposta inválida, sem tempo) encerra a revisão e a
 * matéria segue com a versão que tem.
 */
class ReviewLoop
{
    public function __construct(private ReportReviewer $reviewer, private EnricherManager $enrichers) {}

    /**
     * @param  ?string  $driverError  o erro da análise (ela caiu na extração automática)
     * @param  ?float  $deadline  até quando (microtime) o job pode chamar a IA; nulo, o orçamento do revisor
     * @return array{0: EnrichmentResult, 1: ?ReviewOutcome} a versão que vai ao ar e como a revisão terminou (nulo: etapa desligada ou nada muda no ar)
     */
    public function run(Article $article, ?NewsCase $existing, EnrichmentResult $result, ?string $driverError = null, ?float $deadline = null): array
    {
        $mode = ReportDraft::mode($existing, $result);

        if (! $this->reviewer->enabled() || $mode === null) {
            return [$result, null];
        }

        // A revisão é do driver da análise: o que escreveu o rascunho responde pelo parecer e pela refação.
        $driver = $this->enrichers->driver($result->driver);

        $skip = match (true) {
            $driverError !== null => 'a análise saiu sem a IA ('.$driverError.')',
            ! $driver instanceof ChatDriver => "o driver {$result->driver} não fala com o revisor",
            ! $driver->isReady() => "o driver {$result->driver} não está configurado",
            default => null,
        };

        if ($skip !== null) {
            return [$result, $this->log($article, ReviewOutcome::skipped($mode, $skip))];
        }

        $started = microtime(true);
        $deadline = min($deadline ?? INF, $started + (int) config('secmon.review.budget'));
        $max = max(0, (int) config('secmon.review.max_adjustments'));
        $rounds = [];
        $calls = $adjustments = 0;

        // Por referência: o fim lê as rodadas e as contas de quando a revisão parou.
        $end = function (string $status, ?string $reason = null) use ($article, $mode, $started, &$rounds, &$adjustments, &$calls) {
            return $this->log($article, new ReviewOutcome(
                $status, $mode, $rounds, $adjustments, $calls, $reason, (int) round(microtime(true) - $started),
            ));
        };

        try {
            while (true) {
                if (! self::fits($deadline, (int) config('secmon.review.timeout'), $driver->chatReserve())) {
                    return [$result, $end($rounds === [] ? ReportReview::SKIPPED : ReportReview::PENDING, 'sem tempo no job para o parecer')];
                }

                $this->mark($article, 'parecer', count($rounds) + 1, $max + 1);
                $calls++;

                try {
                    $verdict = $this->reviewer->review($driver, $article, $existing, $result, $mode, $rounds);
                } catch (Throwable $e) {
                    // Sem parecer depois de um ajuste: o que foi pedido não foi conferido.
                    return [$result, $end($rounds === [] ? ReportReview::SKIPPED : ReportReview::PENDING, 'o revisor falhou: '.$e->getMessage())];
                }

                $rounds[] = $verdict->toArray();

                if ($verdict->approved()) {
                    return [$result, $end(ReportReview::APPROVED)];
                }

                if ($adjustments >= $max) {
                    return [$result, $end(ReportReview::PENDING, 'reprovado no parecer final')];
                }

                if (! self::fits($deadline, $driver->chatTimeout(), $driver->chatReserve())) {
                    return [$result, $end(ReportReview::PENDING, 'sem tempo no job para refazer')];
                }

                $this->mark($article, 'refação', count($rounds), $max + 1);
                $calls++;

                try {
                    $redo = $driver->revise($article, $mode === 'update' ? $existing : null, $result, $mode, $verdict);
                } catch (Throwable $e) {
                    return [$result, $end(ReportReview::PENDING, 'a análise não refez: '.$e->getMessage())];
                }

                $result = $verdict->scope === ReviewVerdict::PART
                    ? ReportDraft::apply($result, $redo, $verdict->fields())
                    : ReportDraft::replace($result, $redo);
                $adjustments++;
            }
        } finally {
            Cache::deleteMultiple(self::progressKeys($article));
        }
    }

    /**
     * Em que passo está a revisão, para o "IA agora" (Admin → Coletas): pelo
     * artigo (a análise) ou pela matéria dele (o refazer, que é por matéria).
     *
     * @return ?array{step: string, round: int, rounds: int}
     */
    public static function progress(?int $articleId, ?int $caseId = null): ?array
    {
        return ($articleId ? Cache::get('report-review:article:'.$articleId) : null)
            ?? ($caseId ? Cache::get('report-review:case:'.$caseId) : null);
    }

    /** Cabe mais uma chamada de até `$timeout` s (mais a folga do driver) antes do prazo? */
    private static function fits(float $deadline, int $timeout, int $reserve): bool
    {
        return microtime(true) + $timeout + $reserve <= $deadline;
    }

    private function mark(Article $article, string $step, int $round, int $rounds): void
    {
        Cache::putMany(array_fill_keys(self::progressKeys($article), ['step' => $step, 'round' => $round, 'rounds' => $rounds]), 3600);
    }

    /** @return list<string> */
    private static function progressKeys(Article $article): array
    {
        return array_filter(['report-review:article:'.$article->id, $article->case_id ? 'report-review:case:'.$article->case_id : null]);
    }

    private function log(Article $article, ReviewOutcome $outcome): ReviewOutcome
    {
        Log::info('Revisor do relatório', [
            'article_id' => $article->id,
            'status' => $outcome->status,
            'ajustes' => $outcome->adjustments,
            'chamadas' => $outcome->calls,
            'motivo' => $outcome->reason,
        ]);

        return $outcome;
    }
}
