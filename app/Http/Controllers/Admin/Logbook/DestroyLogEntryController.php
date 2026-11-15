<?php

namespace App\Http\Controllers\Admin\Logbook;

use App\Http\Controllers\Controller;
use App\Models\LogEntry;
use App\Services\Admin\LogbookManager;
use Illuminate\Http\RedirectResponse;

class DestroyLogEntryController extends Controller
{
    public function __construct(private LogbookManager $logbook) {}

    public function __invoke(LogEntry $entry): RedirectResponse
    {
        $this->logbook->delete($entry);

        return back()->with('status', 'Registro removido do diário de bordo.');
    }
}
