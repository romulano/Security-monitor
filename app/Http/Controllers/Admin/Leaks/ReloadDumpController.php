<?php

namespace App\Http\Controllers\Admin\Leaks;

use App\Http\Controllers\Controller;
use App\Services\Leaks\LeakScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Carregar de novo um arquivo que já foi carregado antes.
 *
 * A varredura ignora o que está `done`; isto é a saída manual para quando o admin quer repetir —
 * depois de corrigir a lista de genéricos, por exemplo. É seguro: a carga é idempotente.
 */
class ReloadDumpController extends Controller
{
    public function __construct(private LeakScanner $scanner) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $path = (string) $request->string('path');

        if ($path === '') {
            return back()->with('error', 'Informe o arquivo.');
        }

        return back()->with(
            ...($this->scanner->enqueueAgain($path, $request->user())
                ? ['status', "{$path} entrou na fila."]
                : ['error', "{$path} já está na fila."]),
        );
    }
}
