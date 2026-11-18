<?php

namespace App\Http\Controllers\Admin\Leaks;

use App\Http\Controllers\Controller;
use App\Models\FreeMailDomain;
use App\Services\Admin\LeakPanel;
use Illuminate\Http\RedirectResponse;

class DestroyFreeMailController extends Controller
{
    public function __construct(private LeakPanel $panel) {}

    public function __invoke(FreeMailDomain $domain): RedirectResponse
    {
        $value = $domain->value;

        $this->panel->removeFreeMail($domain);

        return back()->with('status', "{$value} saiu da lista: a próxima carga passa a guardar esse e-mail.");
    }
}
