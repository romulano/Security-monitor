<?php

namespace App\Http\Controllers\Alerts\Keys;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Keys\ShowKeyDomainRequest;
use App\Services\Keys\KeysPanel;
use Illuminate\View\View;

/** As chaves de um domínio comprovado, para o dono decidir se cada uma pode ficar exposta. */
class ShowKeyDomainController extends Controller
{
    public function __construct(private KeysPanel $panel) {}

    public function __invoke(ShowKeyDomainRequest $request): View
    {
        return view('alerts.keys.domain', $this->panel->domain($request->user(), $request->owned()));
    }
}
