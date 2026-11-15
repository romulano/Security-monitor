<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreCompanySectorRequest;
use App\Services\Company\Sectors;
use Illuminate\Http\RedirectResponse;

class StoreCompanySectorController extends Controller
{
    public function __construct(private Sectors $sectors) {}

    public function __invoke(StoreCompanySectorRequest $request): RedirectResponse
    {
        $sector = $this->sectors->create($request->user(), $request->name());

        return redirect()->route('company.sectors')->with('status', "Setor {$sector->name} criado. Agora ponha as contas nele.");
    }
}
