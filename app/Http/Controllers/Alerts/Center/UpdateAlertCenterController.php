<?php

namespace App\Http\Controllers\Alerts\Center;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Center\UpdateAlertCenterRequest;
use App\Services\Alerts\AlertCenter;
use Illuminate\Http\RedirectResponse;

class UpdateAlertCenterController extends Controller
{
    public function __construct(private AlertCenter $center) {}

    public function __invoke(UpdateAlertCenterRequest $request): RedirectResponse
    {
        $changed = $this->center->save($request->user(), $request->choices());

        return back()->with('status', $changed->isEmpty() ? 'Nada mudou no que cada app recebe.' : 'Salvo. Vale a partir do próximo alerta.');
    }
}
