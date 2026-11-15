<?php

namespace App\Http\Controllers\Alerts\Keys;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Keys\ShowKeysRequest;
use App\Services\Keys\KeysPanel;
use Illuminate\View\View;

/** /alertas/chaves: os domínios comprovados da conta, a varredura de chaves de cada um e o resumo do que ela achou. */
class ShowKeysController extends Controller
{
    public function __construct(private KeysPanel $panel) {}

    public function __invoke(ShowKeysRequest $request): View
    {
        return view('alerts.keys.index', $this->panel->page($request->user()));
    }
}
