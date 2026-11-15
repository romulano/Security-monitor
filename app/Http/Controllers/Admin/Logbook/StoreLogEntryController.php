<?php

namespace App\Http\Controllers\Admin\Logbook;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Logbook\StoreLogEntryRequest;
use App\Services\Admin\LogbookManager;
use Illuminate\Http\RedirectResponse;

class StoreLogEntryController extends Controller
{
    public function __construct(private LogbookManager $logbook) {}

    public function __invoke(StoreLogEntryRequest $request): RedirectResponse
    {
        $this->logbook->create($request->validated());

        return back()->with('status', 'Registro adicionado ao diário de bordo.');
    }
}
