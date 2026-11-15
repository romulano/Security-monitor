<?php

namespace App\Http\Controllers\Alerts\Github;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Github\ShowGithubRequest;
use App\Services\Github\GithubPanel;
use Illuminate\View\View;

/** /alertas/github: o convite para a entrevista ou os achados, com as duas análises da IA. */
class ShowGithubController extends Controller
{
    public function __construct(private GithubPanel $panel) {}

    public function __invoke(ShowGithubRequest $request): View
    {
        return view('alerts.github.index', $this->panel->page($request->user(), $request->monitors(), $request->monitor(), $request->filter()));
    }
}
