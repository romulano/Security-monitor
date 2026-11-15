<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\ShowLookalikeRequest;
use App\Models\LookalikeDomain;
use App\Services\Lookalike\LookalikeRadar;
use Illuminate\View\View;

class ShowLookalikeController extends Controller
{
    public function __construct(private LookalikeRadar $radar) {}

    public function __invoke(ShowLookalikeRequest $request, LookalikeDomain $lookalike): View
    {
        return view('alerts.lookalikes.show', $this->radar->detailPage($lookalike));
    }
}
