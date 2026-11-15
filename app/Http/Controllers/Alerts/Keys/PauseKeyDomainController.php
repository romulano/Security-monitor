<?php

namespace App\Http\Controllers\Alerts\Keys;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Keys\PauseKeyDomainRequest;
use App\Services\Keys\KeySites;
use Illuminate\Http\RedirectResponse;

/** Pausa a varredura semanal do domínio (as chaves e as decisões ficam). */
class PauseKeyDomainController extends Controller
{
    public function __construct(private KeySites $sites) {}

    public function __invoke(PauseKeyDomainRequest $request): RedirectResponse
    {
        $owned = $request->owned();
        $this->sites->pauseDomain($owned);

        return back()->with('status', "Varredura de {$owned->domain} pausada. As chaves e as suas decisões ficam.");
    }
}
