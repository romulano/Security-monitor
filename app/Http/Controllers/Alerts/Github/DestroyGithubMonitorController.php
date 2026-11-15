<?php

namespace App\Http\Controllers\Alerts\Github;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Github\DestroyGithubMonitorRequest;
use App\Services\Github\GithubPanel;
use Illuminate\Http\RedirectResponse;

class DestroyGithubMonitorController extends Controller
{
    public function __construct(private GithubPanel $panel) {}

    public function __invoke(DestroyGithubMonitorRequest $request): RedirectResponse
    {
        $this->panel->delete($request->monitor());

        return redirect()->route('alerts.github')->with('status', 'Monitoramento do GitHub excluído, com as respostas e os achados.');
    }
}
