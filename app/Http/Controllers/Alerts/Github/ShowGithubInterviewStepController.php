<?php

namespace App\Http\Controllers\Alerts\Github;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Github\ShowGithubInterviewStepRequest;
use App\Services\Github\GithubInterview;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Um passo da entrevista do GitHub (separada da entrevista de alertas). */
class ShowGithubInterviewStepController extends Controller
{
    public function __construct(private GithubInterview $interview) {}

    public function __invoke(ShowGithubInterviewStepRequest $request, int $passo): View|RedirectResponse
    {
        $monitor = $request->monitor();

        // Monitoramento novo de empresa: começa no passo 1, onde ele nasce com o setor.
        if ($monitor === null && $passo !== 1) {
            return redirect()->route('alerts.github.interview', ['passo' => 1]);
        }

        return view('alerts.github.interview', $this->interview->stepPage($request->user(), $monitor ?? $this->interview->blank(), $passo));
    }
}
