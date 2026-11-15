<?php

namespace App\Http\Controllers\Admin\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Monitoring\ShowAccessesRequest;
use App\Services\Admin\AccessReport;
use Illuminate\View\View;

class ShowAccessesController extends Controller
{
    public function __construct(private AccessReport $report) {}

    public function __invoke(ShowAccessesRequest $request): View
    {
        return view('admin.accesses', $this->report->overview($request->validated()) + ['mais' => $request->validated('mais')]);
    }
}
