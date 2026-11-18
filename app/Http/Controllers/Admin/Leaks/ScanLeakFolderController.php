<?php

namespace App\Http\Controllers\Admin\Leaks;

use App\Http\Controllers\Controller;
use App\Services\Leaks\LeakScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** O admin pediu: olhe a pasta e carregue os .txt que faltam. */
class ScanLeakFolderController extends Controller
{
    public function __construct(private LeakScanner $scanner) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $result = $this->scanner->scan($request->user());

        if (! $result['ok']) {
            return back()->with('error', 'Não consegui ler a pasta: '.$result['error']);
        }

        if ($result['enfileirados'] === 0) {
            return back()->with('status', sprintf(
                'Nada novo para carregar: %d arquivo(s) na pasta, %d já na fila, %d já carregado(s).',
                $result['encontrados'], $result['ja_na_fila'], $result['ja_carregados'],
            ));
        }

        return back()->with('status', sprintf(
            '%d arquivo(s) na fila. A carga roda no worker; esta tela mostra o resultado de cada um.',
            $result['enfileirados'],
        ));
    }
}
