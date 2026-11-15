<?php

namespace App\Http\Controllers\Admin\Alerts\Domains;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\Domains\ListDomainsRequest;
use App\Services\Admin\MonitoredDomainManager;
use Illuminate\View\View;

class ListDomainsController extends Controller
{
    public function __construct(private MonitoredDomainManager $domains) {}

    public function __invoke(ListDomainsRequest $request): View
    {
        return view('admin.alerts.domains', $this->domains->page($request->logId()));
    }
}
