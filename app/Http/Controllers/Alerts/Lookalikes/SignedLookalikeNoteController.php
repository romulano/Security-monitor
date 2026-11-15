<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Exceptions\ActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\SignedLookalikeNoteRequest;
use App\Models\LookalikeDomain;
use App\Services\Lookalike\LookalikeVerdicts;
use Illuminate\View\View;

/** O motivo escrito na página que os botões do aviso abrem (também assinado, sem login): uma vez por domínio. */
class SignedLookalikeNoteController extends Controller
{
    public function __construct(private LookalikeVerdicts $verdicts) {}

    public function __invoke(SignedLookalikeNoteRequest $request, LookalikeDomain $lookalike): View
    {
        try {
            $flagged = $this->verdicts->saveNoteOnce($lookalike, $request->action(), $request->note());
        } catch (ActionRefused $refused) {
            // Sem login não há tela anterior para o aviso: ele aparece na própria página.
            return view('alerts.lookalikes.signed-verdict', [...$this->verdicts->signedPage($lookalike), 'error' => $refused->getMessage()]);
        }

        return view('alerts.lookalikes.signed-verdict', [
            ...$this->verdicts->signedPage($lookalike),
            'status' => $flagged ? LookalikeVerdicts::FLAGGED_NOTE : 'Motivo salvo. A IA usa nos próximos achados.',
        ]);
    }
}
