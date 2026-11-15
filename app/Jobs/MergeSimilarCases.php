<?php

namespace App\Jobs;

use App\Models\NewsCase;
use App\Services\Crawler\AnalysisAttempts;
use App\Services\Hermes\HermesLimitReached;
use App\Services\Merge\CaseMerger;
use App\Services\Merge\MergeEditor;
use App\Support\Prompts;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Leva um grupo de matérias parecidas à IA: ela confirma quais são a mesma
 * história e escreve a matéria nova de cada uma, que o CaseMerger grava. Com
 * uma matéria só (já consolidada e com fontes novas), a IA só a atualiza.
 *
 * Fila `merge`: o worker de análise só a atende com a `enrich` vazia, então a
 * fusão usa as vagas do hermes sem atrasar a análise das notícias novas.
 */
class MergeSimilarCases implements ShouldQueue
{
    use Queueable;

    // Erro ou tempo esgotado: falha na primeira vez, como antes. Só o limite de uso
    // da IA devolve o grupo à fila (release), até a hora que o hermes dá, por um dia.
    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    // Até 1 + N chamadas de IA (confirmar e escrever cada grupo); o hermes leva até 240 s cada.
    public int $timeout = 1800;

    /** @param  list<int>  $caseIds */
    public function __construct(public array $caseIds)
    {
        $this->onQueue('merge');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    /** Grupo que a IA já recusou inteiro não volta a ela enquanto ninguém mudar. */
    public static function rejectedKey(array $caseIds): string
    {
        sort($caseIds);

        return 'merge:rejected:'.implode('-', $caseIds);
    }

    public function handle(MergeEditor $editor, CaseMerger $merger): void
    {
        if (! $editor->enabled()) {
            return;
        }

        // A análise de um artigo novo dessas matérias espera a fusão (e a
        // fusão, a análise em curso): cada uma lê o relatório já com a outra.
        $ids = $this->caseIds;
        sort($ids);
        $locks = array_map(fn (int $id) => NewsCase::reportLock($id, $this->timeout), $ids);

        foreach ($locks as $lock) {
            $lock->block(330);
        }

        try {
            $this->fold($editor, $merger);
        } catch (HermesLimitReached $e) {
            // A conta da IA esgotou (25/10: oito grupos da fusão das 00:00 falharam assim).
            // O grupo espera na fila a hora que o hermes deu, com 5 minutos de folga.
            $wait = max(60, (int) now()->diffInSeconds($e->resetsAt->copy()->addMinutes(5)));
            Log::info('Fusão de matérias: a IA está no limite de uso; o grupo volta à fila', ['casos' => $this->caseIds, 'volta' => $e->resetsAt->toIso8601String()]);
            $this->release($wait);
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }

    private function fold(MergeEditor $editor, CaseMerger $merger): void
    {
        $cases = NewsCase::query()->whereIn('id', $this->caseIds)->where('is_published', true)->orderBy('id')->get();

        if (count($this->caseIds) === 1) {
            if (($case = $cases->first()) && $this->stageOn($merger, $cases)) {
                $merger->rewrite($case, retry(2, fn () => $this->report($editor, $merger, $cases), 2000, self::worthRetrying(...)));
                app(AnalysisAttempts::class)->recordFusion([$case->id], null);
                Log::info('Fusão de matérias: matéria consolidada atualizada com as fontes novas', ['caso' => $case->uuid]);
            }

            return;
        }

        if ($cases->count() < 2) {
            return;
        }

        if (! Prompts::stageOn('fusao-agrupar')) {
            Log::info('Fusão de matérias: "agrupar" desligada em Prompts; o grupo não foi à IA', ['casos' => $cases->pluck('uuid')->all()]);

            return;
        }

        // Resposta inutilizável do hermes acontece: tenta mais uma vez.
        $groups = retry(2, fn () => $editor->confirm($cases), 2000, self::worthRetrying(...));

        if ($groups === []) {
            Cache::put(self::rejectedKey($cases->modelKeys()), true, now()->addDays((int) config('secmon.merge.window_days')));
            Log::info('Fusão de matérias: a IA manteve as matérias separadas', ['casos' => $cases->pluck('uuid')->all()]);

            return;
        }

        foreach ($groups as $group) {
            $members = $cases->whereIn('id', $group['ids'])->values();

            if (! $this->stageOn($merger, $members)) {
                continue;
            }

            try {
                $report = retry(2, fn () => $this->report($editor, $merger, $members), 2000, self::worthRetrying(...));
                // Os artigos do grupo estão todos na matéria nova.
                if ($merged = $merger->merge($group['ids'], $report, $group['reason'])) {
                    app(AnalysisAttempts::class)->recordFusion([$merged->id], null);
                }
            } catch (HermesLimitReached $e) {
                throw $e;
            } catch (Throwable $e) {
                app(AnalysisAttempts::class)->recordFusion($group['ids'], $e->getMessage());
                // Um grupo com problema não segura os outros. Para tentar de
                // novo: secmon:cases-merge --case=<uuid> --case=<uuid>.
                Log::warning('Fusão de matérias: grupo não foi juntado', ['casos' => $group['ids'], 'erro' => $e->getMessage()]);
            }
        }
    }

    /** Resposta inutilizável pode vir certa na segunda; o limite de uso, não. */
    private static function worthRetrying(Throwable $e): bool
    {
        return ! $e instanceof HermesLimitReached;
    }

    /**
     * A etapa de que o grupo precisa está ligada na aba Prompts: escrever do
     * zero (primeiro agrupamento) ou enriquecer (já há matéria consolidada).
     *
     * @param  Collection<int, NewsCase>  $cases
     */
    private function stageOn(CaseMerger $merger, Collection $cases): bool
    {
        $stage = $merger->base($cases) ? 'fusao-enriquecer' : 'fusao-escrever';

        if (Prompts::stageOn($stage)) {
            return true;
        }

        Log::info('Fusão de matérias: etapa desligada em Prompts, pulada', ['etapa' => $stage, 'casos' => $cases->pluck('uuid')->all()]);

        return false;
    }

    /**
     * Primeiro agrupamento: a IA escreve a matéria do zero, com todas as
     * fontes. Com matéria já consolidada no grupo, ela enriquece o relatório
     * dessa matéria com o que ele ainda não leu, sem refazê-lo.
     *
     * @param  Collection<int, NewsCase>  $cases
     * @return array{title: string, summary: string, title_pt: ?string, summary_pt: ?string}
     */
    private function report(MergeEditor $editor, CaseMerger $merger, Collection $cases): array
    {
        $base = $merger->base($cases);

        if (! $base) {
            return $editor->write($merger->sources($cases->modelKeys()));
        }

        $reports = $cases->filter(fn (NewsCase $case) => $case->consolidated_at !== null && ! $case->is($base))->values();
        $articles = $merger->unread($cases);

        // Nada que o relatório ainda não tenha lido: ele continua como está.
        if ($reports->isEmpty() && $articles->isEmpty()) {
            return $base->only(['title', 'summary', 'title_pt', 'summary_pt']);
        }

        return $editor->enrich($base, $reports, $articles);
    }
}
