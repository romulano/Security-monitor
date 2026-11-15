<?php

namespace App\Http\Controllers\Admin\Articles;

use App\Http\Controllers\Controller;
use App\Services\Admin\ArticleManager;
use Illuminate\Http\RedirectResponse;

/** Admin → Coletas: "Refazer com a IA" as matérias que ficaram com a extração heurística. */
class RebuildWithoutAiController extends Controller
{
    public function __construct(private ArticleManager $articles) {}

    public function __invoke(): RedirectResponse
    {
        $cases = $this->articles->rebuildWithoutAi();

        return back()->with('status', "{$cases} matéria(s) na fila para refazer com a IA. Elas são reescritas no lugar (o link continua o mesmo).");
    }
}
