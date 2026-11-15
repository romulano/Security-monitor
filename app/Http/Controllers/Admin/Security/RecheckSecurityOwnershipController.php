<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Models\SecuritySite;
use App\Services\Admin\SecuritySiteManager;
use Illuminate\Http\RedirectResponse;

class RecheckSecurityOwnershipController extends Controller
{
    public function __construct(private SecuritySiteManager $sites) {}

    public function __invoke(SecuritySite $site): RedirectResponse
    {
        $proof = $this->sites->recheckOwnership($site);

        return $proof
            ? back()->with('status', 'Posse comprovada ('.($proof === 'server' ? 'o site aponta para o servidor do Security Monitor' : 'registro TXT encontrado').'). As verificações de arquivos expostos passam a rodar.')
            : back()->with('error', 'Ainda não achamos o registro TXT. Depois de criá-lo no DNS, pode levar alguns minutos para valer.');
    }
}
