<?php

namespace App\Http\Controllers\Alerts\Keys;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Keys\ScanKeyDomainRequest;
use App\Services\Keys\KeySites;
use Illuminate\Http\RedirectResponse;

/** "Ativar e varrer" e "Varrer agora": a 1ª ativa a varredura semanal do domínio. */
class ScanKeyDomainController extends Controller
{
    public function __construct(private KeySites $sites) {}

    public function __invoke(ScanKeyDomainRequest $request): RedirectResponse
    {
        $owned = $request->owned();
        $this->sites->activate($owned, $request->user());

        return back()->with('status', "Varredura de {$owned->domain} na fila: leva alguns minutos. Depois, ela se repete toda semana.");
    }
}
