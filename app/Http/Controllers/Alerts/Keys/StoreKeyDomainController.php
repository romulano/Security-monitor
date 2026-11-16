<?php

namespace App\Http\Controllers\Alerts\Keys;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Keys\StoreKeyDomainRequest;
use App\Services\Keys\KeyDomains;
use Illuminate\Http\RedirectResponse;

/** Cadastra um domínio direto em Chaves expostas. A varredura só começa no "Ativar e varrer" dele. */
class StoreKeyDomainController extends Controller
{
    public function __construct(private KeyDomains $domains) {}

    public function __invoke(StoreKeyDomainRequest $request): RedirectResponse
    {
        [$owned, $entered] = $this->domains->add($request->user(), $request->string('dominio')->toString());

        return redirect()->route('alerts.keys')->with('status', $entered
            ? "{$owned->domain} adicionado. Clique em “Ativar e varrer” para a primeira varredura."
            : "{$owned->domain} já estava na lista.");
    }
}
