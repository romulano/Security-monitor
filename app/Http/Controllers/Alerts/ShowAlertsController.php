<?php

namespace App\Http\Controllers\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\ShowAlertsRequest;
use App\Services\Alerts\AlertInbox;
use Illuminate\View\View;

/** /alertas: o agregador — tudo o que as três fontes acharam, com os filtros, o motivo, a avaliação e o reenvio. */
class ShowAlertsController extends Controller
{
    public function __construct(private AlertInbox $inbox) {}

    public function __invoke(ShowAlertsRequest $request): View
    {
        return view('alerts.index', $this->inbox->page($request->user(), $request->source(), $request->filter(), $request->page()));
    }
}
