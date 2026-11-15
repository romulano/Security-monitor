<?php

namespace App\Http\Controllers\Admin\Articles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Articles\ShowCollectionsRequest;
use App\Services\Admin\CollectionsReport;
use Illuminate\View\View;

class ShowCollectionsController extends Controller
{
    public function __construct(private CollectionsReport $report) {}

    public function __invoke(ShowCollectionsRequest $request): View
    {
        return view('admin.collections', $this->report->page($request->validated()));
    }
}
