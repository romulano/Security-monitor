<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\RenameCompanySectorRequest;
use App\Models\Sector;
use App\Services\Company\Sectors;
use Illuminate\Http\RedirectResponse;

class RenameCompanySectorController extends Controller
{
    public function __construct(private Sectors $sectors) {}

    public function __invoke(RenameCompanySectorRequest $request, Sector $sector): RedirectResponse
    {
        $sector = $this->sectors->rename($request->user(), $sector, $request->name());

        return redirect()->route('company.sectors')->with('status', "O setor agora se chama {$sector->name}.");
    }
}
