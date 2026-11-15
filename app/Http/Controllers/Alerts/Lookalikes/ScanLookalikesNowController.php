<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\ScanLookalikesNowRequest;
use App\Services\Lookalike\LookalikeRadar;
use Illuminate\Http\RedirectResponse;

/** "Verificar agora": 1 vez a cada 24 h (a varredura das 01:00 continua). */
class ScanLookalikesNowController extends Controller
{
    public function __construct(private LookalikeRadar $radar) {}

    public function __invoke(ScanLookalikesNowRequest $request): RedirectResponse
    {
        $this->radar->scanNow($request->watch());

        return back()->with('status', 'Varredura na fila. Os nomes novos aparecem aqui em alguns minutos.');
    }
}
