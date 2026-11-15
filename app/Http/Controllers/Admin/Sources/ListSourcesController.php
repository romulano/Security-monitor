<?php

namespace App\Http\Controllers\Admin\Sources;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Sources\ListSourcesRequest;
use App\Services\Admin\SourceManager;
use Illuminate\View\View;

class ListSourcesController extends Controller
{
    public function __construct(private SourceManager $sources) {}

    public function __invoke(ListSourcesRequest $request): View
    {
        return view('admin.sources', ['sources' => $this->sources->list($request->search())]);
    }
}
