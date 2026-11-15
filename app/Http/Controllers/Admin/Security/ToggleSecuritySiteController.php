<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Models\SecuritySite;
use App\Services\Admin\SecuritySiteManager;
use Illuminate\Http\RedirectResponse;

class ToggleSecuritySiteController extends Controller
{
    public function __construct(private SecuritySiteManager $sites) {}

    public function __invoke(SecuritySite $site): RedirectResponse
    {
        $enabled = $this->sites->toggle($site);

        return back()->with('status', $enabled ? "{$site->host} voltou para a monitoria." : "{$site->host} pausado: nenhuma verificação até retomar.");
    }
}
