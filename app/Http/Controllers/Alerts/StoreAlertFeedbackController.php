<?php

namespace App\Http\Controllers\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\StoreAlertFeedbackRequest;
use App\Models\AlertMatch;
use App\Services\Alerts\AlertFeedback;
use Illuminate\Http\RedirectResponse;

/** "Relevante" ou "Não é relevante" (com o motivo opcional) no histórico de Meus alertas. */
class StoreAlertFeedbackController extends Controller
{
    public function __construct(private AlertFeedback $feedback) {}

    public function __invoke(StoreAlertFeedbackRequest $request, AlertMatch $match): RedirectResponse
    {
        $flagged = $this->feedback->rate($match, $request->feedback(), $request->note(), $request->unaffected());

        return back()->with('status', $flagged
            ? AlertFeedback::FLAGGED_NOTE
            : 'Obrigado: a IA usa essa avaliação para decidir os próximos alertas.');
    }
}
