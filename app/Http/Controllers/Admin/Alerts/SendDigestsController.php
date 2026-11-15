<?php

namespace App\Http\Controllers\Admin\Alerts;

use App\Http\Controllers\Controller;
use App\Services\Admin\AlertsOverview;
use Illuminate\Http\RedirectResponse;

class SendDigestsController extends Controller
{
    public function __construct(private AlertsOverview $overview) {}

    public function __invoke(): RedirectResponse
    {
        $output = $this->overview->sendDigests();

        return back()->with('status', $output.' Os que já saíram hoje não repetem.');
    }
}
