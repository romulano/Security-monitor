<?php

namespace App\Http\Controllers\Alerts\Keys;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Keys\ResumeKeyDomainRequest;
use App\Services\Keys\KeySites;
use Illuminate\Http\RedirectResponse;

/** Volta a varredura semanal do domínio pausado. */
class ResumeKeyDomainController extends Controller
{
    public function __construct(private KeySites $sites) {}

    public function __invoke(ResumeKeyDomainRequest $request): RedirectResponse
    {
        $owned = $request->owned();
        $this->sites->resumeDomain($owned);

        return back()->with('status', "Varredura de {$owned->domain} de volta: a próxima sai na vez dela.");
    }
}
