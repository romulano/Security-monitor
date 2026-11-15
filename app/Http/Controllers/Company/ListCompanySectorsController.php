<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Services\Auth\Reauthentication;
use App\Services\Company\Sectors;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ListCompanySectorsController extends Controller
{
    public function __construct(private Sectors $sectors, private Reauthentication $reauth) {}

    public function __invoke(Request $request): View
    {
        return view('company.sectors', $this->sectors->overview($request->user()) + ['reauth' => $this->reauth->options($request->user(), $request->session())]);
    }
}
