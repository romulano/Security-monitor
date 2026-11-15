<?php

namespace App\Http\Controllers\Admin\Cases;

use App\Http\Controllers\Controller;
use App\Services\Admin\CaseManager;
use Illuminate\Http\RedirectResponse;

class ReindexCasesController extends Controller
{
    public function __construct(private CaseManager $cases) {}

    public function __invoke(): RedirectResponse
    {
        $total = $this->cases->reindex();

        return back()->with('status', "{$total} casos reindexados.");
    }
}
