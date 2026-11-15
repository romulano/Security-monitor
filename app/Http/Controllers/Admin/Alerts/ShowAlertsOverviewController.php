<?php

namespace App\Http\Controllers\Admin\Alerts;

use App\Http\Controllers\Controller;
use App\Services\Admin\AlertsOverview;
use Illuminate\View\View;

class ShowAlertsOverviewController extends Controller
{
    public function __construct(private AlertsOverview $overview) {}

    public function __invoke(): View
    {
        return view('admin.alerts.overview', $this->overview->page());
    }
}
