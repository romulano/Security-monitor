<?php

namespace App\Http\Controllers\Admin\Alerts\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\Clients\ToggleClientPauseRequest;
use App\Models\User;
use App\Services\Admin\AlertClientManager;
use Illuminate\Http\RedirectResponse;

class ToggleClientPauseController extends Controller
{
    public function __construct(private AlertClientManager $clients) {}

    public function __invoke(ToggleClientPauseRequest $request, User $user): RedirectResponse
    {
        $paused = $this->clients->togglePause($request->profile());

        return back()->with('status', $paused ? 'Alertas do cliente pausados.' : 'Alertas do cliente retomados.');
    }
}
