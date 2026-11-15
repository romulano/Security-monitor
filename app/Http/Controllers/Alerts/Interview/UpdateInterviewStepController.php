<?php

namespace App\Http\Controllers\Alerts\Interview;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Interview\UpdateInterviewStepRequest;
use App\Services\Alerts\InterviewService;
use Illuminate\Http\RedirectResponse;

class UpdateInterviewStepController extends Controller
{
    public function __construct(private InterviewService $interview) {}

    public function __invoke(UpdateInterviewStepRequest $request, int $passo): RedirectResponse
    {
        $extra = ['consent' => $request->consent(), 'ip' => $request->ip()];

        if ($request->startsNew()) {
            $profile = $this->interview->startWith($request->user(), $request->answers(), $extra);
        } else {
            $profile = $this->interview->profileFor($request->user(), $request->alertId());
            $this->interview->save($profile, $passo, $request->answers(), $extra);
        }

        // No passo do domínio o cliente fica na tela para ver o TXT e o resultado,
        // e também enquanto houver sugestão do DNS sem resposta ("Pular" segue sem decidir).
        $suggestions = $passo === 5 ? $this->interview->dnsSuggestionsFor($profile) : [];

        if ($suggestions !== []) {
            return redirect()->to(route('alerts.interview', $profile->urlParams(['passo' => $passo])).'#sugestoes-dns')
                ->with('status', 'Achamos '.count($suggestions).' tecnologia(s) no DNS público do domínio. Quer adicionar ao perfil?');
        }

        if ($request->stays()) {
            return redirect()->route('alerts.interview', $profile->urlParams(['passo' => $passo]))->with('status', 'Salvo.');
        }

        return redirect()->route('alerts.interview', $profile->urlParams(['passo' => $passo + 1]));
    }
}
