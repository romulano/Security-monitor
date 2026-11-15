<?php

namespace App\Http\Controllers\Admin\Alerts\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\Clients\ShowClientRequest;
use App\Models\User;
use App\Services\Admin\AlertClientManager;
use Illuminate\View\View;

class ShowClientController extends Controller
{
    public function __construct(private AlertClientManager $clients) {}

    public function __invoke(ShowClientRequest $request, User $user): View
    {
        return view('admin.alerts.client', $this->clients->detailPage($user, $request->profile(), $request->preview()));
    }
}
