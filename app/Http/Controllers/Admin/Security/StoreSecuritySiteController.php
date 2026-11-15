<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Security\StoreSecuritySiteRequest;
use App\Services\Admin\SecuritySiteManager;
use Illuminate\Http\RedirectResponse;

class StoreSecuritySiteController extends Controller
{
    public function __construct(private SecuritySiteManager $sites) {}

    public function __invoke(StoreSecuritySiteRequest $request): RedirectResponse
    {
        $site = $this->sites->create($request->user(), $request->host(), (string) $request->ip());

        return redirect()->route('admin.security.show', $site)->with('status', "{$site->host} entrou na monitoria. A primeira verificação sai nos próximos minutos.");
    }
}
