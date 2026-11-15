<?php

namespace App\Http\Controllers\Admin\Alerts\Deliveries;

use App\Http\Controllers\Controller;
use App\Models\NotificationDelivery;
use App\Services\Admin\DeliveryManager;
use Illuminate\Http\RedirectResponse;

class RetryDeliveryController extends Controller
{
    public function __construct(private DeliveryManager $deliveries) {}

    public function __invoke(NotificationDelivery $delivery): RedirectResponse
    {
        $this->deliveries->retry($delivery);

        return back()->with('status', 'Envio recolocado na fila.');
    }
}
