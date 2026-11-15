<?php

namespace App\Http\Controllers\Admin\Sources;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Sources\UpdateSourceRequest;
use App\Models\Source;
use App\Services\Admin\SourceManager;
use Illuminate\Http\RedirectResponse;

class UpdateSourceController extends Controller
{
    public function __construct(private SourceManager $sources) {}

    public function __invoke(UpdateSourceRequest $request, Source $source): RedirectResponse
    {
        $this->sources->update($source, $request->validated());

        return back()->with('status', 'Fonte atualizada.');
    }
}
