<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\VerifyRadarDomainRequest;
use App\Services\Lookalike\RadarInterview;
use Illuminate\Http\RedirectResponse;

/** "Verificar agora": procura o registro TXT do domínio do Radar. */
class VerifyRadarDomainController extends Controller
{
    public function __construct(private RadarInterview $interview) {}

    public function __invoke(VerifyRadarDomainRequest $request): RedirectResponse
    {
        $watch = $request->watch();
        $this->interview->verify($watch);

        return back()->with('status', $watch->isCompleted()
            ? 'Domínio comprovado. A primeira varredura entra na fila em até 15 minutos.'
            : 'Domínio comprovado. Agora é só ativar o Radar.');
    }
}
