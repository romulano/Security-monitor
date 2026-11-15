<?php

namespace App\Http\Controllers\Alerts\Github;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Github\ScanGithubNowRequest;
use App\Services\Github\GithubPanel;
use Illuminate\Http\RedirectResponse;

/** "Verificar agora": uma varredura fora de hora, 1 vez a cada 24 h. */
class ScanGithubNowController extends Controller
{
    public function __construct(private GithubPanel $panel) {}

    public function __invoke(ScanGithubNowRequest $request): RedirectResponse
    {
        $this->panel->scanNow($request->monitor());

        return back()->with('status', 'Verificação na fila. Os achados novos aparecem aqui em alguns minutos.');
    }
}
