<?php

namespace App\Http\Controllers\Alerts\Github;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Github\ToggleGithubPauseRequest;
use App\Services\Github\GithubPanel;
use Illuminate\Http\RedirectResponse;

class ToggleGithubPauseController extends Controller
{
    public function __construct(private GithubPanel $panel) {}

    public function __invoke(ToggleGithubPauseRequest $request): RedirectResponse
    {
        $paused = $this->panel->togglePause($request->monitor());

        return back()->with('status', $paused
            ? 'Monitoramento pausado: nada é buscado nem enviado até você retomar.'
            : 'Monitoramento retomado. A próxima varredura começa em instantes.');
    }
}
