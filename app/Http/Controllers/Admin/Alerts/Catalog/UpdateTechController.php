<?php

namespace App\Http\Controllers\Admin\Alerts\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\Catalog\UpdateTechRequest;
use App\Models\TechCatalog;
use App\Services\Admin\TechCatalogManager;
use Illuminate\Http\RedirectResponse;

class UpdateTechController extends Controller
{
    public function __construct(private TechCatalogManager $catalog) {}

    public function __invoke(UpdateTechRequest $request, TechCatalog $tech): RedirectResponse
    {
        $count = $this->catalog->update($tech, $request->fields());

        return back()->with('status', "{$tech->name} salva. {$count} perfil(is) recompilado(s).");
    }
}
