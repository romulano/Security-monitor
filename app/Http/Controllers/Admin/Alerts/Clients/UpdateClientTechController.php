<?php

namespace App\Http\Controllers\Admin\Alerts\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\Clients\UpdateClientTechRequest;
use App\Models\User;
use App\Services\Admin\AlertClientManager;
use Illuminate\Http\RedirectResponse;

class UpdateClientTechController extends Controller
{
    public function __construct(private AlertClientManager $clients) {}

    public function __invoke(UpdateClientTechRequest $request, User $user): RedirectResponse
    {
        $this->clients->updateTech($request->profile(), $request->techId(), $request->adding());

        return back()->with('status', $request->adding() ? 'Tecnologia adicionada ao perfil.' : 'Tecnologia removida do perfil.');
    }
}
