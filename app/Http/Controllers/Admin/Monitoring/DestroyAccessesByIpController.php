<?php

namespace App\Http\Controllers\Admin\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Monitoring\DestroyAccessesByIpRequest;
use App\Services\Admin\AccessEraser;
use Illuminate\Http\RedirectResponse;

/** "Apagar tudo deste IP", na ficha: todos os acessos do IP, no período inteiro; a ficha fica vazia, e volta para a Visão geral. */
class DestroyAccessesByIpController extends Controller
{
    public function __construct(private AccessEraser $eraser) {}

    public function __invoke(DestroyAccessesByIpRequest $request): RedirectResponse
    {
        [$count, $devices] = $this->eraser->deleteIp($ip = $request->address());

        return redirect()->route('admin.accesses')
            ->with('status', ($count === 1 ? '1 registro apagado' : "{$count} registros apagados")
                .($devices ? ($devices === 1 ? ' e 1 código de aparelho' : " e {$devices} códigos de aparelho") : '')." (o IP {$ip}).");
    }
}
