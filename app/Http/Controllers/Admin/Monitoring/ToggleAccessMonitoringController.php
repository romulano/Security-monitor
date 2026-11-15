<?php

namespace App\Http\Controllers\Admin\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\PageViewRecorder;
use Illuminate\Http\RedirectResponse;

/** Liga ou desliga o registro dos acessos; o que já foi gravado fica até o prazo de 6 meses. */
class ToggleAccessMonitoringController extends Controller
{
    public function __construct(private PageViewRecorder $views) {}

    public function __invoke(): RedirectResponse
    {
        return back()->with('status', $this->views->toggle()
            ? 'Monitoria de acessos ligada: as próximas páginas abertas no site aparecem aqui.'
            : 'Monitoria de acessos desligada: nada novo é gravado; o que já existe some no prazo de 6 meses.');
    }
}
