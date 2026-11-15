<?php

namespace App\Http\Controllers\Alerts\Github;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Github\FinishGithubInterviewRequest;
use App\Services\Github\GithubInterview;
use Illuminate\Http\RedirectResponse;

/** "Ativar monitoramento" na revisão: na primeira vez, a varredura começa já. */
class FinishGithubInterviewController extends Controller
{
    public function __construct(private GithubInterview $interview) {}

    public function __invoke(FinishGithubInterviewRequest $request): RedirectResponse
    {
        $first = $this->interview->finish($monitor = $request->monitor());

        return redirect()->route('alerts.github', $monitor->urlParams())->with('status', $first
            ? 'Monitoramento ativado. A primeira varredura já começou; os achados aparecem aqui em alguns minutos.'
            : 'Alterações salvas. Valem a partir da próxima varredura.');
    }
}
