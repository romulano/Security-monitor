<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\ShowLookalikesRequest;
use App\Services\Lookalike\LookalikeRadar;
use Illuminate\View\View;

/**
 * /alertas/radar-de-dominios: nomes parecidos com o domínio de um Radar do
 * cliente, com o nível de risco, os prints em cada aparelho, o parecer da IA
 * e o kit de denúncia.
 */
class ShowLookalikesController extends Controller
{
    public function __construct(private LookalikeRadar $radar) {}

    public function __invoke(ShowLookalikesRequest $request): View
    {
        return view('alerts.lookalikes.index', $this->radar->listPage($request->user(), $request->watches(), $request->watch(), $request->filter()));
    }
}
