<?php

namespace App\Http\Controllers\Admin\Cases;

use App\Http\Controllers\Controller;
use App\Models\NewsCase;
use App\Services\Admin\CaseManager;
use Illuminate\Http\RedirectResponse;

/** Tira a matéria do feed público (fora de segurança), sem apagar, ou devolve a ele. */
class ToggleCaseVisibilityController extends Controller
{
    public function __construct(private CaseManager $cases) {}

    public function __invoke(NewsCase $case): RedirectResponse
    {
        $visible = $this->cases->toggleVisibility($case);

        return back()->with('status', $visible
            ? 'A matéria voltou ao feed público.'
            : 'Matéria fora do feed público: sai da busca, do painel, da API e dos alertas, e a página dá 410 para quem não é admin.');
    }
}
