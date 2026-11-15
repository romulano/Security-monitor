<?php

namespace App\Http\Controllers\Admin\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Monitoring\ShowAccessOriginsRequest;
use App\Services\Admin\AccessReport;
use Illuminate\View\View;

class ShowAccessOriginsController extends Controller
{
    public function __construct(private AccessReport $report) {}

    public function __invoke(ShowAccessOriginsRequest $request): View
    {
        return view('admin.accesses.origins', $this->report->origins($request->validated()) + ['mais' => $request->validated('mais')]);
    }
}
