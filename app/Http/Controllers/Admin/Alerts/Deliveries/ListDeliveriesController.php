<?php

namespace App\Http\Controllers\Admin\Alerts\Deliveries;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\Deliveries\ListDeliveriesRequest;
use App\Services\Admin\DeliveryManager;
use Illuminate\View\View;

class ListDeliveriesController extends Controller
{
    public function __construct(private DeliveryManager $deliveries) {}

    public function __invoke(ListDeliveriesRequest $request): View
    {
        return view('admin.alerts.deliveries', $this->deliveries->page($request->filters(), $request->payloadId()));
    }
}
