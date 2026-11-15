<?php

namespace App\Http\Controllers\Admin\Sources;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Sources\StoreSourceRequest;
use App\Services\Admin\SourceManager;
use Illuminate\Http\RedirectResponse;

class StoreSourceController extends Controller
{
    public function __construct(private SourceManager $sources) {}

    public function __invoke(StoreSourceRequest $request): RedirectResponse
    {
        $this->sources->create($request->validated());

        return back()->with('status', 'Fonte adicionada.');
    }
}
