<?php

namespace App\Http\Controllers\Admin\Leaks;

use App\Http\Controllers\Controller;
use App\Services\Admin\LeakPanel;
use App\Services\Leaks\LeakScanner;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Admin → Vazamentos: a fila do humano, os dumps carregados e a lista de e-mail genérico. */
class ShowLeaksController extends Controller
{
    private const TABS = ['fila', 'resolvidas', 'descartadas', 'genericos', 'dumps'];

    public function __construct(private LeakPanel $panel, private LeakScanner $scanner) {}

    public function __invoke(Request $request): View
    {
        $tab = in_array($request->query('ver'), self::TABS, true) ? $request->query('ver') : 'fila';
        $status = ['fila' => 'pending', 'resolvidas' => 'resolved', 'descartadas' => 'discarded'][$tab] ?? null;

        return view('admin.leaks', [
            'tab' => $tab,
            'counts' => $this->panel->counts(),
            'lines' => $status === null ? null : $this->panel->lines($status),
            'domains' => $tab === 'genericos' ? $this->panel->freeMail('domain') : null,
            'brands' => $tab === 'genericos' ? $this->panel->freeMail('brand') : null,
            'freeCounts' => $this->panel->freeMailCounts(),
            'sources' => $tab === 'dumps' ? $this->panel->sources() : null,
            // A aba lista a pasta de vazamentos: se não der para ler, ela devolve o motivo, e a tela mostra
            // o erro em vez de uma pasta vazia, que seria indistinguível de "não tem arquivo".
            'folder' => $tab === 'dumps' ? $this->scanner->look() : null,
            'dumps' => $tab === 'dumps' ? $this->panel->dumps() : null,
            'deletes' => $this->scanner->deletesAfterImport(),
        ]);
    }
}
