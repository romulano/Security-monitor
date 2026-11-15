<?php

namespace App\Http\Controllers\Admin\Alerts\Catalog;

use App\Http\Controllers\Controller;
use App\Models\TechCatalog;
use App\Services\Admin\TechCatalogManager;
use Illuminate\Http\RedirectResponse;

class ToggleTechController extends Controller
{
    public function __construct(private TechCatalogManager $catalog) {}

    public function __invoke(TechCatalog $tech): RedirectResponse
    {
        $count = $this->catalog->toggle($tech);

        return back()->with('status', $tech->name.($tech->active ? ' ativada' : ' desativada')." · {$count} perfil(is) recompilado(s).");
    }
}
