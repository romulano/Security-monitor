<?php

namespace App\Http\Controllers\Admin\Alerts\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\Clients\TestClientRequest;
use App\Models\User;
use App\Services\Admin\AlertClientManager;
use Illuminate\Http\RedirectResponse;

class TestClientController extends Controller
{
    public function __construct(private AlertClientManager $clients) {}

    public function __invoke(TestClientRequest $request, User $user): RedirectResponse
    {
        $channels = $request->channels();
        $this->clients->sendTest($channels);

        return back()->with('status', 'Mensagem de teste enfileirada para o '.$channels->map->appName()->implode(' e o ').' do cliente.');
    }
}
