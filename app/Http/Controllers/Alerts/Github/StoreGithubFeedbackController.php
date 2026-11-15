<?php

namespace App\Http\Controllers\Alerts\Github;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Github\StoreGithubFeedbackRequest;
use App\Models\GithubFinding;
use App\Services\Github\GithubFeedback;
use Illuminate\Http\RedirectResponse;

/** "Relevante" ou "Não é relevante" (com o motivo opcional) num achado do GitHub. */
class StoreGithubFeedbackController extends Controller
{
    public function __construct(private GithubFeedback $feedback) {}

    public function __invoke(StoreGithubFeedbackRequest $request, GithubFinding $finding): RedirectResponse
    {
        $flagged = $this->feedback->rate($finding, $request->feedback(), $request->note(), $request->unaffected());

        return back()->with('status', $flagged
            ? GithubFeedback::FLAGGED_NOTE
            : 'Obrigado: a IA usa essa avaliação para decidir os próximos avisos do GitHub.');
    }
}
