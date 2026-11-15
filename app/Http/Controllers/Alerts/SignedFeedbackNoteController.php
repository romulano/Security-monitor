<?php

namespace App\Http\Controllers\Alerts;

use App\Exceptions\ActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\SignedFeedbackNoteRequest;
use App\Models\AlertMatch;
use App\Services\Alerts\AlertFeedback;
use Illuminate\View\View;

/** Motivo escrito na página do link assinado: uma vez por alerta, sem trocar o voto já registrado. */
class SignedFeedbackNoteController extends Controller
{
    public function __construct(private AlertFeedback $feedback) {}

    public function __invoke(SignedFeedbackNoteRequest $request, AlertMatch $match): View
    {
        try {
            $flagged = $this->feedback->rateOnce($match, $request->feedback(), $request->note(), $request->unaffected());
        } catch (ActionRefused $refused) {
            // Sem login não há "voltar com aviso": o erro aparece na própria página.
            return view('alerts.feedback', $this->feedback->page($match, error: $refused->getMessage()));
        }

        return view('alerts.feedback', $this->feedback->page($match, status: $flagged
            ? AlertFeedback::FLAGGED_NOTE
            : 'Motivo salvo. A IA vai usá-lo nos próximos alertas.'));
    }
}
