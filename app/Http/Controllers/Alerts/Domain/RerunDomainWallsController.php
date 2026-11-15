<?php

namespace App\Http\Controllers\Alerts\Domain;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Domain\RerunDomainWallsRequest;
use App\Services\Alerts\Discovery\DiscoveryService;
use Illuminate\Http\RedirectResponse;

/**
 * Depois de liberar o robô no firewall: relê só as páginas que foram
 * barradas, 1 vez por dia. Não é uma varredura nova (essa é mensal).
 */
class RerunDomainWallsController extends Controller
{
    public function __construct(private DiscoveryService $discovery) {}

    public function __invoke(RerunDomainWallsRequest $request): RedirectResponse
    {
        $this->discovery->queueWallsRetry($request->domain());

        return back()->with('status', 'Vamos ler de novo só as páginas barradas. O resultado aparece aqui em instantes.');
    }
}
