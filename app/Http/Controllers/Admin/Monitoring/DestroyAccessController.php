<?php

namespace App\Http\Controllers\Admin\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Monitoring\DestroyAccessRequest;
use App\Models\PageView;
use App\Services\Admin\AccessEraser;
use Illuminate\Http\RedirectResponse;

/** O × de um acesso na ficha do IP: só aquele registro; volta para a ficha, no mesmo período. */
class DestroyAccessController extends Controller
{
    public function __construct(private AccessEraser $eraser) {}

    public function __invoke(DestroyAccessRequest $request, PageView $view): RedirectResponse
    {
        $ip = $view->ip;
        $count = $this->eraser->delete($view);

        return redirect()->route('admin.accesses.ip', ['ip' => $ip, 'periodo' => $request->validated('periodo') ?? '180d', 'page' => $request->validated('page')])
            ->with('status', $count === 1 ? '1 registro apagado.' : "{$count} registros apagados.");
    }
}
