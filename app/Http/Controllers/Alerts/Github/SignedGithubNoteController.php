<?php

namespace App\Http\Controllers\Alerts\Github;

use App\Exceptions\ActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Github\SignedGithubNoteRequest;
use App\Models\GithubFinding;
use App\Services\Github\GithubFeedback;
use Illuminate\View\View;

/** Motivo escrito na página do link assinado: uma vez por achado, sem trocar o voto já registrado. */
class SignedGithubNoteController extends Controller
{
    public function __construct(private GithubFeedback $feedback) {}

    public function __invoke(SignedGithubNoteRequest $request, GithubFinding $finding): View
    {
        try {
            $flagged = $this->feedback->rateOnce($finding, $request->feedback(), $request->note(), $request->unaffected());
        } catch (ActionRefused $refused) {
            return view('alerts.github.feedback', $this->feedback->page($finding, error: $refused->getMessage()));
        }

        return view('alerts.github.feedback', $this->feedback->page($finding, status: $flagged
            ? GithubFeedback::FLAGGED_NOTE
            : 'Motivo salvo. A IA vai usá-lo nos próximos avisos do GitHub.'));
    }
}
