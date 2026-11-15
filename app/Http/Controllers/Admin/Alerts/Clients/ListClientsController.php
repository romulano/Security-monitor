<?php

namespace App\Http\Controllers\Admin\Alerts\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\Clients\ListClientsRequest;
use App\Services\Admin\AlertClientManager;
use Illuminate\View\View;

class ListClientsController extends Controller
{
    public function __construct(private AlertClientManager $clients) {}

    public function __invoke(ListClientsRequest $request): View
    {
        return view('admin.alerts.clients', $this->clients->listPage($request->filters()));
    }
}
