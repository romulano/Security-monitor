<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\SignedLookalikeVerdictRequest;
use App\Models\LookalikeDomain;
use App\Services\Lookalike\LookalikeVerdicts;
use Illuminate\View\View;

/** "É golpe", "É nosso" ou "Não é ameaça" direto da mensagem do Slack, Discord ou Telegram: a assinatura substitui o login. */
class SignedLookalikeVerdictController extends Controller
{
    public function __construct(private LookalikeVerdicts $verdicts) {}

    public function __invoke(SignedLookalikeVerdictRequest $request, LookalikeDomain $lookalike, string $acao): View
    {
        // Uso único: o segundo clique (neste botão ou em outro) só mostra a resposta registrada.
        $saved = $this->verdicts->saveOnce($lookalike, $acao);

        return view('alerts.lookalikes.signed-verdict', $this->verdicts->signedPage($lookalike, already: ! $saved));
    }
}
