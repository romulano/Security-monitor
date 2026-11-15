<?php

namespace App\Services\Admin;

use App\Exceptions\ActionRefused;
use App\Models\NewsCase;
use App\Services\Search\CaseIndex;
use Throwable;

/** Admin: reindexar o Elasticsearch, tirar uma matéria do feed público (ou devolver) e apagar uma matéria. */
class CaseManager
{
    public function __construct(private CaseIndex $index) {}

    /** Recria o índice com os casos publicados; devolve quantos entraram. */
    public function reindex(): int
    {
        if (! $this->index->ping()) {
            throw new ActionRefused('Elasticsearch inacessível.');
        }

        $this->index->create();
        $total = 0;

        NewsCase::query()->where('is_published', true)->chunkById(200, function ($cases) use (&$total) {
            $total += $this->index->bulk($cases);
        });

        $this->index->pruneHidden();
        $this->index->refresh();

        return $total;
    }

    /**
     * Esconde a matéria do feed público ou devolve a ela; devolve se ficou à
     * vista. Nada é apagado, e a busca acompanha na hora.
     */
    public function toggleVisibility(NewsCase $case): bool
    {
        $case->isHidden() ? $case->unhide() : $case->hide('admin');

        try {
            $this->index->put($case);
        } catch (Throwable $e) {
            report($e);

            // O banco já mudou; a escondida sai da busca no próximo boot, e a devolvida volta no reindex.
            throw new ActionRefused('A matéria mudou, mas a busca não acompanhou (Elasticsearch fora). Quando ele voltar, use "Reindexar busca" no admin.');
        }

        return ! $case->isHidden();
    }

    public function delete(NewsCase $case): void
    {
        $uuid = $case->uuid;
        $case->delete();
        $this->index->forget($uuid);
    }
}
