<?php

namespace App\Http\Controllers\Admin\Alerts\Domains;

use App\Http\Controllers\Controller;
use App\Models\MonitoredDomain;
use App\Services\Admin\MonitoredDomainManager;
use Illuminate\Http\RedirectResponse;

class RunDomainDiscoveryController extends Controller
{
    public function __construct(private MonitoredDomainManager $domains) {}

    public function __invoke(MonitoredDomain $domain): RedirectResponse
    {
        $this->domains->runDiscovery($domain);

        return redirect()->route('admin.alerts.domains', ['log' => $domain->id])->with('status', "Descoberta de {$domain->domain} enfileirada.");
    }
}
