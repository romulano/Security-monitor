<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Models\SecuritySite;
use App\Services\Admin\SecurityOverview;
use Illuminate\View\View;

class ShowSecuritySiteController extends Controller
{
    public function __construct(private SecurityOverview $overview) {}

    public function __invoke(SecuritySite $site): View
    {
        return view('admin.security.show', $this->overview->show($site));
    }
}
