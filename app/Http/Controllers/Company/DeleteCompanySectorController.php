<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\DeleteCompanySectorRequest;
use App\Services\Company\Sectors;
use Illuminate\Http\RedirectResponse;

class DeleteCompanySectorController extends Controller
{
    public function __construct(private Sectors $sectors) {}

    public function __invoke(DeleteCompanySectorRequest $request): RedirectResponse
    {
        $counts = $this->sectors->delete($request->user(), $request->sectorId(), $request->expected());

        return redirect()->route('company.sectors')->with('status', 'Setor apagado. '.Sectors::summary($counts));
    }
}
