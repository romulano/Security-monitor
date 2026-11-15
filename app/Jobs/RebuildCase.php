<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Crawler\CaseRebuilder;
use App\Services\Review\ReportReviewer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Refaz um caso no lugar (mesmo link), na fila de análise. É o que roda depois
 * que o admin cola o texto completo de um artigo que só tinha o resumo do feed,
 * ou pede "Refazer com a IA" em Admin → Coletas.
 *
 * Um artigo por job: matéria com dezenas de fontes leva horas de IA, e num job
 * só ela estourava o timeout ou era cortada pelo deploy (e, com uma tentativa,
 * falhava inteira uma hora depois). Cada passo grava o seu artigo e põe o
 * próximo na fila; o passo cortado no meio roda de novo.
 *
 * Matéria grande (mais de `secmon.rebuild.full_max_articles` artigos) não é
 * refeita do zero: só o artigo que pediu o refazer, e os dela que ficaram sem a
 * análise da IA, são relidos contra a matéria atual (CaseRebuilder::refreshIds).
 */
class RebuildCase implements ShouldQueue
{
    use Queueable;

    // A segunda tentativa é para o passo cortado pelo deploy ou pelo worker.
    public int $tries = 2;

    // Uma chamada de IA; o hermes leva até 240 s. Com o revisor ligado, mais o tempo dele.
    public int $timeout = 600;

    // Trava a matéria enquanto os passos rodam (renovada a cada passo).
    private const LOCK_SECONDS = 3 * 3600;

    /**
     * Artigos que faltam, na ordem da coleta (null: começo). Fora do construtor
     * para ter padrão no job serializado antes dos passos (Admin → Fila, "Tentar de novo").
     *
     * @var ?list<int>
     */
    public ?array $remaining = null;

    public int $step = 0;

    /** Matéria grande: cada artigo é relido contra a matéria atual (padrão false no job antigo). */
    public bool $incremental = false;

    /** O que pediu o refazer (ArticleAttempt::TRIGGERS), para o histórico dos artigos. */
    public string $trigger = 'refazer-materia';

    /** @param  ?int  $articleId  o artigo que pediu a reanálise (para o alerta no fim) */
    public function __construct(public int $caseId, public ?int $articleId = null, ?array $remaining = null, int $step = 0, bool $incremental = false, string $trigger = 'refazer-materia')
    {
        $this->remaining = $remaining;
        $this->step = $step;
        $this->incremental = $incremental;
        $this->timeout = 600 + ReportReviewer::extraSeconds();
        $this->trigger = $trigger;
        $this->onQueue('enrich');
    }

    /** Põe a matéria na fila, a não ser que ela já esteja sendo refeita. */
    public static function start(int $caseId, ?int $articleId = null, string $trigger = 'refazer-materia'): bool
    {
        if (! Cache::add(self::lockKey($caseId), true, self::LOCK_SECONDS)) {
            return false;
        }

        self::dispatch($caseId, $articleId, trigger: $trigger);

        return true;
    }

    public function handle(CaseRebuilder $rebuilder): void
    {
        $deadline = microtime(true) + $this->timeout - 30;
        $case = NewsCase::query()->find($this->caseId);

        if (! $case) {
            $this->unlock();

            return;
        }

        Cache::put(self::lockKey($this->caseId), true, self::LOCK_SECONDS);

        $remaining = $this->remaining;
        $incremental = $this->incremental;

        // O primeiro passo decide: do zero (matéria pequena) ou só os artigos que pediram.
        if ($remaining === null) {
            $incremental = ! $rebuilder->fullRebuild($case);
            $remaining = $incremental ? $rebuilder->refreshIds($case, $this->articleId) : $rebuilder->articleIds($case);
        }

        $article = $remaining === [] ? null : Article::query()->find(array_shift($remaining));

        if ($article) {
            $incremental
                ? $rebuilder->refreshArticle($case, $article, $deadline, $this->trigger)
                : $rebuilder->rebuildArticle($case, $article, $this->step === 0, $deadline, $this->trigger);
        }

        if ($remaining !== []) {
            self::dispatch($this->caseId, $this->articleId, $remaining, $this->step + 1, $incremental, $this->trigger);

            return;
        }

        $this->unlock();

        // A reanálise no lugar não alerta sozinha, mas com o texto completo o
        // caso pode passar a interessar a um perfil que antes não casava. O
        // matching não repete alerta já registrado (perfil + caso + artigo).
        $trigger = $this->articleId ? Article::query()->find($this->articleId) : null;

        if ($trigger?->newsCase) {
            MatchCaseToProfiles::dispatchFor($trigger->newsCase, $trigger->id, $trigger->is_first ? 'new_case' : 'case_update');
        }
    }

    /** Passo que falhou de vez: a matéria volta a poder ser refeita. */
    public function failed(): void
    {
        $this->unlock();
    }

    private function unlock(): void
    {
        Cache::forget(self::lockKey($this->caseId));
    }

    private static function lockKey(int $caseId): string
    {
        return 'rebuild-case:'.$caseId;
    }
}
