<?php

namespace App\Http\Controllers\Admin\Sources;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Sources\RunAllSourcesRequest;
use App\Services\Admin\SourceManager;
use Illuminate\Http\RedirectResponse;

class RunAllSourcesController extends Controller
{
    public function __construct(private SourceManager $sources) {}

    public function __invoke(RunAllSourcesRequest $request): RedirectResponse
    {
        $this->sources->runAll($request->force());

        return back()->with('status', 'Coleta enfileirada — o resultado aparece aqui conforme os bots publicam.');
    }
}
