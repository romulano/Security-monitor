<?php

namespace App\Http\Controllers\Admin\Sources;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Services\Admin\SourceManager;
use Illuminate\Http\RedirectResponse;

class ToggleSourceController extends Controller
{
    public function __construct(private SourceManager $sources) {}

    public function __invoke(Source $source): RedirectResponse
    {
        $enabled = $this->sources->toggle($source);

        return back()->with('status', $source->host.($enabled ? ' ligada.' : ' desligada.'));
    }
}
