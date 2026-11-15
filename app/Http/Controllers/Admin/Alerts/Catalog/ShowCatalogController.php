<?php

namespace App\Http\Controllers\Admin\Alerts\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\Catalog\ShowCatalogRequest;
use App\Services\Admin\TechCatalogManager;
use Illuminate\View\View;

class ShowCatalogController extends Controller
{
    public function __construct(private TechCatalogManager $catalog) {}

    public function __invoke(ShowCatalogRequest $request): View
    {
        return view('admin.alerts.catalog', $this->catalog->page($request->filters(), $request->editingId(), $request->creating()));
    }
}
