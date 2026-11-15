<?php

namespace App\Http\Controllers\Alerts;

use App\Http\Controllers\Controller;
use App\Models\AlertMatch;
use App\Services\Alerts\AlertFeedback;
use Illuminate\View\View;

/** Link "Relevante" da mensagem (Slack, Discord, Telegram): URL assinada, sem login. */
class SignedRelevantController extends Controller
{
    public function __construct(private AlertFeedback $feedback) {}

    public function __invoke(AlertMatch $match): View
    {
        // Uso único: o segundo clique (neste link ou no oposto) só mostra a resposta registrada.
        $voted = $this->feedback->vote($match, 'relevant');

        return view('alerts.feedback', $this->feedback->page($match, already: ! $voted));
    }
}
