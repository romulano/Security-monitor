<?php

namespace App\Http\Controllers\Alerts\Github;

use App\Http\Controllers\Controller;
use App\Models\GithubFinding;
use App\Services\Github\GithubFeedback;
use Illuminate\View\View;

/** "Relevante" e "Não é relevante" direto da mensagem (Slack, Discord, Telegram): URL assinada, sem login. */
class SignedGithubVoteController extends Controller
{
    public function __construct(private GithubFeedback $feedback) {}

    public function __invoke(GithubFinding $finding, string $acao): View
    {
        // Uso único: o segundo clique (neste link ou no oposto) só mostra a resposta registrada.
        $voted = $this->feedback->vote($finding, $acao);

        return view('alerts.github.feedback', $this->feedback->page($finding, already: ! $voted));
    }
}
