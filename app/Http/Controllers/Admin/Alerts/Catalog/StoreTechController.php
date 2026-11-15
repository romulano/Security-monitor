<?php

namespace App\Http\Controllers\Admin\Alerts\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\Catalog\StoreTechRequest;
use App\Services\Admin\TechCatalogManager;
use Illuminate\Http\RedirectResponse;

class StoreTechController extends Controller
{
    public function __construct(private TechCatalogManager $catalog) {}

    public function __invoke(StoreTechRequest $request): RedirectResponse
    {
        $tech = $this->catalog->create($request->fields());

        return redirect()->route('admin.alerts.catalog', ['editar' => $tech->id])->with('status', "{$tech->name} adicionada ao catálogo.");
    }
}
