<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Models\SecuritySite;
use App\Services\Admin\SecuritySiteManager;
use Illuminate\Http\RedirectResponse;

class RunSecurityScanController extends Controller
{
    public function __construct(private SecuritySiteManager $sites) {}

    public function __invoke(SecuritySite $site): RedirectResponse
    {
        $this->sites->runNow($site);

        return back()->with('status', "{$site->host} na fila de verificação. Atualize a página em alguns instantes.");
    }
}
