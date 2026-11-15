<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Models\SecuritySite;
use App\Services\Admin\SecuritySiteManager;
use Illuminate\Http\RedirectResponse;

class DestroySecuritySiteController extends Controller
{
    public function __construct(private SecuritySiteManager $sites) {}

    public function __invoke(SecuritySite $site): RedirectResponse
    {
        $this->sites->delete($site);

        return redirect()->route('admin.security')->with('status', "{$site->host} saiu da monitoria, com o histórico dele.");
    }
}
