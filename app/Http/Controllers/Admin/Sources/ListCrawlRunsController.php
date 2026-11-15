<?php

namespace App\Http\Controllers\Admin\Sources;

use App\Http\Controllers\Controller;
use App\Services\Admin\SourceManager;
use Illuminate\View\View;

class ListCrawlRunsController extends Controller
{
    public function __construct(private SourceManager $sources) {}

    public function __invoke(): View
    {
        return view('admin.runs', $this->sources->runsPage());
    }
}
