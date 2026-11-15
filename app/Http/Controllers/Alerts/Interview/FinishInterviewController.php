<?php

namespace App\Http\Controllers\Alerts\Interview;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Interview\FinishInterviewRequest;
use App\Services\Alerts\AlertPanel;
use App\Services\Alerts\InterviewService;
use Illuminate\Http\RedirectResponse;

/** "Salvar perfil" na revisão: ativa o alerta (ou grava a edição); se falta resposta obrigatória, volta ao passo dela. */
class FinishInterviewController extends Controller
{
    public function __construct(
        private InterviewService $interview,
        private AlertPanel $alerts,
    ) {}

    public function __invoke(FinishInterviewRequest $request): RedirectResponse
    {
        $profile = $this->interview->profileFor($request->user(), $request->alertId());

        if ($missing = $this->interview->missingRequired($profile)) {
            return redirect()->route('alerts.interview', $profile->urlParams(['passo' => $missing->step]))
                ->withErrors(["answers.{$missing->key}" => 'Esta resposta é obrigatória.']);
        }

        $first = $this->interview->activate($profile);

        if (! $this->alerts->hasChannel($request->user())) {
            return redirect()->route('alerts.notifiers')->with('status', 'Perfil salvo. Agora escolha onde avisar você: Slack, Discord, Telegram ou um webhook.');
        }

        $name = $profile->hasSiblings() ? ' “'.$profile->displayName().'”' : '';

        return redirect()->route('alerts.news')->with('status', $first ? "Alerta{$name} ativado." : "Alerta{$name} atualizado.");
    }
}
