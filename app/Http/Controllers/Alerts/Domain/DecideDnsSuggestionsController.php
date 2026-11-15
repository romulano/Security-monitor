<?php

namespace App\Http\Controllers\Alerts\Domain;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Domain\DecideDnsSuggestionsRequest;
use App\Services\Alerts\InterviewService;
use Illuminate\Http\RedirectResponse;

/** Passo do domínio: o cliente diz quais tecnologias achadas no DNS público entram no perfil. */
class DecideDnsSuggestionsController extends Controller
{
    public function __construct(private InterviewService $interview) {}

    public function __invoke(DecideDnsSuggestionsRequest $request): RedirectResponse
    {
        $domain = $request->domain();
        $added = $this->interview->decideDnsSuggestions($domain->profile, $domain, $request->accepted());

        if ($added === []) {
            return back()->with('status', 'Sugestões dispensadas. Elas não voltam nas próximas leituras.');
        }

        $names = count($added) > 1 ? implode(', ', array_slice($added, 0, -1)).' e '.end($added) : $added[0];

        return back()->with('status', $names.(count($added) > 1 ? ' entraram' : ' entrou').' no perfil.');
    }
}
