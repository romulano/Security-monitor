<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Telemetry;
use Illuminate\View\View;

/** Página "Sobre": a história do Security Monitor, a frota e o trajeto de uma notícia. */
class ShowAboutController extends Controller
{
    public function __construct(private Telemetry $telemetry) {}

    public function __invoke(): View
    {
        return view('about', $this->telemetry->snapshot());
    }
}
