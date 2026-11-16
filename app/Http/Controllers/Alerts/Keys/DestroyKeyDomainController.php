<?php

namespace App\Http\Controllers\Alerts\Keys;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Keys\DestroyKeyDomainRequest;
use App\Services\Keys\KeyDomains;
use Illuminate\Http\RedirectResponse;

/** Tira o domínio de Chaves expostas, com a varredura e as chaves que ela achou. */
class DestroyKeyDomainController extends Controller
{
    public function __construct(private KeyDomains $domains) {}

    public function __invoke(DestroyKeyDomainRequest $request): RedirectResponse
    {
        $owned = $request->owned();
        $this->domains->remove($owned);

        return redirect()->route('alerts.keys')->with('status', "{$owned->domain} removido de Chaves expostas.");
    }
}
