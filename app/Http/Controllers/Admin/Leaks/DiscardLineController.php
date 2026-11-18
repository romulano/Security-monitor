<?php

namespace App\Http\Controllers\Admin\Leaks;

use App\Http\Controllers\Controller;
use App\Models\LeakImportLine;
use App\Services\Admin\LeakPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DiscardLineController extends Controller
{
    public function __construct(private LeakPanel $panel) {}

    public function __invoke(Request $request, LeakImportLine $line): RedirectResponse
    {
        $done = $this->panel->discard($line, $request->user());

        return back()->with($done ? 'status' : 'error', $done
            ? 'Linha descartada.'
            : 'Esta linha já foi decidida.');
    }
}
