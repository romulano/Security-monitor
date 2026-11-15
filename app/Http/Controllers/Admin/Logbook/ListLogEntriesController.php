<?php

namespace App\Http\Controllers\Admin\Logbook;

use App\Http\Controllers\Controller;
use App\Services\Admin\LogbookManager;
use Illuminate\View\View;

class ListLogEntriesController extends Controller
{
    public function __construct(private LogbookManager $logbook) {}

    public function __invoke(): View
    {
        return view('admin.logbook', ['entries' => $this->logbook->list()]);
    }
}
