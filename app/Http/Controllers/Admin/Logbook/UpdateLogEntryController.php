<?php

namespace App\Http\Controllers\Admin\Logbook;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Logbook\UpdateLogEntryRequest;
use App\Models\LogEntry;
use App\Services\Admin\LogbookManager;
use Illuminate\Http\RedirectResponse;

class UpdateLogEntryController extends Controller
{
    public function __construct(private LogbookManager $logbook) {}

    public function __invoke(UpdateLogEntryRequest $request, LogEntry $entry): RedirectResponse
    {
        $this->logbook->update($entry, $request->validated());

        return back()->with('status', 'Registro atualizado.');
    }
}
