<?php

namespace App\Http\Controllers\Admin\Sources;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Services\Admin\SourceManager;
use Illuminate\Http\RedirectResponse;

class DestroySourceController extends Controller
{
    public function __construct(private SourceManager $sources) {}

    public function __invoke(Source $source): RedirectResponse
    {
        $this->sources->delete($source);

        return back()->with('status', 'Fonte removida (as matérias já coletadas ficam).');
    }
}
