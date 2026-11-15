<?php

namespace App\Http\Controllers\Alerts\Github;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Github\ShowGithubFindingRequest;
use App\Models\GithubFinding;
use App\Services\Github\GithubPanel;
use Illuminate\View\View;

/** Um achado: o que foi visto, as duas análises da IA, os valores mascarados e a avaliação. */
class ShowGithubFindingController extends Controller
{
    public function __construct(private GithubPanel $panel) {}

    public function __invoke(ShowGithubFindingRequest $request, GithubFinding $finding): View
    {
        return view('alerts.github.show', $this->panel->detail($request->user(), $finding));
    }
}
