<?php

namespace App\Services\Admin;

use App\Exceptions\ActionRefused;
use App\Jobs\EnrichArticle;
use App\Jobs\RebuildCase;
use App\Models\Article;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Admin → Execuções e Coletas: artigo que falhou na análise volta para a fila. */
class ArticleManager
{
    public function retry(Article $article): void
    {
        $article->forceFill(['status' => 'pending', 'attempts' => 0, 'error' => null])->save();
        EnrichArticle::dispatch($article->id, 'retentar')->onQueue('enrich');
    }

    /**
     * Artigos que viraram matéria sem a IA: ela falhou (limite do hermes, resposta
     * sem JSON, driver sem chave) e entrou a extração heurística. A matéria existe,
     * mas sem a análise; o erro ficou no artigo.
     */
    public function withoutAi(): Builder
    {
        return Article::query()->whereNotNull('case_id')->driverError();
    }

    /** @return Collection<int, object{reason: string, total: int}> quantos por motivo, dos mais comuns aos menos */
    public function withoutAiByReason(): Collection
    {
        return $this->withoutAi()
            ->selectRaw('left(error, 90) as reason, count(*) as total')
            ->groupBy('reason')
            ->orderByDesc('total')
            ->limit(5)
            ->get();
    }

    /**
     * Refaz com a IA, no lugar (mesmo link), cada matéria que tem artigo sem a
     * análise. O RebuildCase trava a matéria até o último artigo; o erro do artigo
     * sai quando a IA responder. Matéria grande relê só os artigos sem a análise
     * (ver CaseRebuilder::fullRebuild). Devolve quantas matérias foram para a fila.
     */
    public function rebuildWithoutAi(): int
    {
        $cases = $this->withoutAi()->selectRaw('case_id, min(id) as article_id')->groupBy('case_id')->get();

        if ($cases->isEmpty()) {
            throw new ActionRefused('Nenhuma matéria está sem a análise da IA.');
        }

        // A matéria que já está sendo refeita não entra de novo.
        $started = $cases->filter(fn ($row) => RebuildCase::start((int) $row->case_id, (int) $row->article_id, 'refazer-ia'))->count();

        if ($started === 0) {
            throw new ActionRefused('As matérias sem a análise da IA já estão sendo refeitas.');
        }

        return $started;
    }
}
