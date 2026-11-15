<?php

namespace App\Http\Controllers\Admin\Alerts;

use App\Http\Controllers\Controller;
use App\Services\Admin\AlertsOverview;
use Illuminate\Http\RedirectResponse;

class RecompileProfilesController extends Controller
{
    public function __construct(private AlertsOverview $overview) {}

    public function __invoke(): RedirectResponse
    {
        $output = $this->overview->recompileProfiles();

        return back()->with('status', $output.' O percolator foi regravado.');
    }
}
