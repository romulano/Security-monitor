<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\ShowAbuseKitRequest;
use App\Models\LookalikeDomain;
use App\Services\Lookalike\LookalikeRadar;
use Illuminate\View\View;

/** Kit de denúncia: quem pode derrubar, o texto pronto, os prints e o que o cliente já enviou. */
class ShowAbuseKitController extends Controller
{
    public function __construct(private LookalikeRadar $radar) {}

    public function __invoke(ShowAbuseKitRequest $request, LookalikeDomain $lookalike): View
    {
        return view('alerts.lookalikes.kit', $this->radar->kitPage($lookalike));
    }
}
