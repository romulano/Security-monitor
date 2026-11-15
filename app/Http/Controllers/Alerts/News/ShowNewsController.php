<?php

namespace App\Http\Controllers\Alerts\News;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\News\ShowNewsRequest;
use App\Services\Alerts\AlertPanel;
use Illuminate\View\View;

/** /alertas/noticias: os alertas de notícias do cliente (um por produto ou site), os canais e as notícias recebidas. */
class ShowNewsController extends Controller
{
    public function __construct(private AlertPanel $alerts) {}

    public function __invoke(ShowNewsRequest $request): View
    {
        return view('alerts.news', $this->alerts->page($request->user(), $request->filter(), $request->alertId()));
    }
}
