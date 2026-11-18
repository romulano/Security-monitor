<?php

namespace App\Http\Controllers\Admin\Leaks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Leaks\StoreFreeMailRequest;
use App\Services\Admin\LeakPanel;
use Illuminate\Http\RedirectResponse;

class StoreFreeMailController extends Controller
{
    public function __construct(private LeakPanel $panel) {}

    public function __invoke(StoreFreeMailRequest $request): RedirectResponse
    {
        $row = $this->panel->addFreeMail($request->entry(), $request->user());

        return back()->with('status', "{$row->value} entrou na lista: a próxima carga já descarta esse e-mail.");
    }
}
