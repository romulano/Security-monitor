<?php

namespace App\Http\Controllers\Admin\Alerts;

use App\Http\Controllers\Controller;
use App\Services\Alerts\Rematcher;
use Illuminate\Http\RedirectResponse;

class RunAlertsMatchController extends Controller
{
    public function __construct(private Rematcher $rematcher) {}

    public function __invoke(): RedirectResponse
    {
        $count = $this->rematcher->run(now()->subDay());

        return back()->with('status', "{$count} casos das últimas 24 h enfileirados para o matching (registra sem notificar).");
    }
}
