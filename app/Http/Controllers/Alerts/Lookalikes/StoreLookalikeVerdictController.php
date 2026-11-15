<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\StoreLookalikeVerdictRequest;
use App\Models\LookalikeDomain;
use App\Services\Lookalike\LookalikeVerdicts;
use Illuminate\Http\RedirectResponse;

/** Avaliação do cliente (com motivo opcional): a IA usa nos próximos achados. */
class StoreLookalikeVerdictController extends Controller
{
    public function __construct(private LookalikeVerdicts $verdicts) {}

    public function __invoke(StoreLookalikeVerdictRequest $request, LookalikeDomain $lookalike): RedirectResponse
    {
        $flagged = $this->verdicts->save($lookalike, $request->action(), $request->note());
        $host = $lookalike->displayHost();

        return back()->with('status', $flagged ? LookalikeVerdicts::FLAGGED_NOTE : match ($request->action()) {
            'nosso' => "{$host} marcado como de vocês. Sai da lista de ameaças, não gera aviso, e a IA aprende com isso.",
            'seguro' => "{$host} marcado como não ameaça. Se ele subir de nível, volta a aparecer.",
            'golpe' => "{$host} marcado como golpe. Use o kit de denúncia para pedir a derrubada.",
            default => "Avaliação de {$host} desfeita.",
        });
    }
}
