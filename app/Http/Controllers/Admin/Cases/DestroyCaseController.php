<?php

namespace App\Http\Controllers\Admin\Cases;

use App\Http\Controllers\Controller;
use App\Models\NewsCase;
use App\Services\Admin\CaseManager;
use Illuminate\Http\RedirectResponse;

class DestroyCaseController extends Controller
{
    public function __construct(private CaseManager $cases) {}

    public function __invoke(NewsCase $case): RedirectResponse
    {
        $this->cases->delete($case);

        return back()->with('status', 'Caso removido.');
    }
}
