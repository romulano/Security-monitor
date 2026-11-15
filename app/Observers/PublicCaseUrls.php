<?php

namespace App\Observers;

use App\Models\CaseMerge;
use App\Models\NewsCase;
use App\Services\IndexNow\IndexNow;
use App\Services\Sitemap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * O endereço público de uma matéria (/materia/{uuid}) entrou, mudou ou saiu do
 * ar: o sitemap é refeito na próxima leitura, e os buscadores do IndexNow
 * recebem o aviso da matéria nova, da que voltou ao feed, da que saiu dele
 * (410) ou foi apagada pelo admin (404) e, na fusão de matérias, da nova e de cada
 * antiga (CaseMerge), cujo link agora redireciona. A fonte nova numa matéria
 * que já existe só muda a data dela no sitemap.
 *
 * Roda depois do commit: o sitemap não guarda uma matéria que a transação
 * desfez, e a trava do cache (no banco, em produção) não entra no meio dela.
 * Uma falha aqui nunca derruba quem gravou a matéria: vai para o log.
 *
 * A fusão apaga as antigas em lote, sem passar pelo modelo; quem cobre é o
 * `created` da matéria nova e o de cada CaseMerge, na mesma transação.
 */
class PublicCaseUrls
{
    /** As colunas que entram na data da matéria no sitemap (Sitemap::LASTMOD). */
    private const DATES = ['latest_at', 'consolidated_at', 'vulnerability_explained_at'];

    public function __construct(private Sitemap $sitemap, private IndexNow $indexNow) {}

    public function created(Model $model): void
    {
        // A matéria recém-criada ainda não leu o padrão do banco (publicada): só o falso gravado a esconde.
        $this->changed($model, announce: ! ($model instanceof NewsCase && $model->isHidden()));
    }

    public function updated(Model $model): void
    {
        if (! $model instanceof NewsCase) {
            return;
        }

        if ($model->wasChanged('is_published')) {
            $this->changed($model, announce: true);
        } elseif ($model->wasChanged(self::DATES)) {
            $this->changed($model, announce: false);
        }
    }

    public function deleted(Model $model): void
    {
        // A escondida já tinha saído do mapa e dos buscadores.
        if ($model instanceof NewsCase) {
            $this->changed($model, announce: ! $model->isHidden());
        }
    }

    /** @param  NewsCase|CaseMerge  $model */
    private function changed(Model $model, bool $announce): void
    {
        $url = rtrim((string) config('app.url'), '/').route('feed.show', $model->getAttribute('uuid'), false);

        DB::afterCommit(function () use ($url, $announce) {
            try {
                $this->sitemap->forget();

                if ($announce) {
                    $this->indexNow->queue([$url]);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
