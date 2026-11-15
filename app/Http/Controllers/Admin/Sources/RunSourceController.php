<?php

namespace App\Http\Controllers\Admin\Sources;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Services\Admin\SourceManager;
use Illuminate\Http\RedirectResponse;

class RunSourceController extends Controller
{
    public function __construct(private SourceManager $sources) {}

    public function __invoke(Source $source): RedirectResponse
    {
        $run = $this->sources->run($source);

        return back()->with('status', sprintf(
            '%s: %d itens, %d novos, %d duplicados, %d falhas.',
            $source->host, $run->items_found, $run->items_new, $run->items_duplicate, $run->items_failed
        ));
    }
}
