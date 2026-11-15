<?php

namespace App\Http\Controllers\Admin\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Monitoring\ShowAccessRobotsRequest;
use App\Services\Admin\AccessReport;
use Illuminate\View\View;

class ShowAccessRobotsController extends Controller
{
    public function __construct(private AccessReport $report) {}

    public function __invoke(ShowAccessRobotsRequest $request): View
    {
        return view('admin.accesses.robots', $this->report->robots($request->validated()) + ['mais' => $request->validated('mais')]);
    }
}
