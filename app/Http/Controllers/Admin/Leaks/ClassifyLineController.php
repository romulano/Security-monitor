<?php

namespace App\Http\Controllers\Admin\Leaks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Leaks\ClassifyLineRequest;
use App\Models\LeakImportLine;
use App\Services\Admin\LeakPanel;
use Illuminate\Http\RedirectResponse;

class ClassifyLineController extends Controller
{
    public function __construct(private LeakPanel $panel) {}

    public function __invoke(ClassifyLineRequest $request, LeakImportLine $line): RedirectResponse
    {
        $result = $this->panel->classify($line, $request->decision(), $request->user());

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok']
            ? 'Credencial gravada e linha fechada.'
            : $result['error']);
    }
}
