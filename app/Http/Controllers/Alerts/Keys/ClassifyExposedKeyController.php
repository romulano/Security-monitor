<?php

namespace App\Http\Controllers\Alerts\Keys;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Keys\ClassifyExposedKeyRequest;
use App\Services\Keys\KeyLedger;
use App\Services\Pentest\Keys\KeyKinds;
use Illuminate\Http\RedirectResponse;

/** "Pode ficar exposta", "Não deveria estar exposta" e "Desfazer" numa chave do domínio. */
class ClassifyExposedKeyController extends Controller
{
    public function __construct(private KeyLedger $ledger) {}

    public function __invoke(ClassifyExposedKeyRequest $request): RedirectResponse
    {
        $key = $request->key();
        $verdict = $request->verdict();
        $this->ledger->classify($key, $request->user(), $verdict);
        $label = KeyKinds::label($key->kind);

        return redirect()->to(route('alerts.keys.domain', ['dominio' => $request->owned()->domain]).'#chave-'.$key->id)->with('status', match ($verdict) {
            'allowed' => "{$label} marcada como uma chave que pode ficar exposta.",
            'leak' => "{$label} marcada como uma chave que não deveria estar exposta. Troque-a no serviço e tire-a do site.",
            default => "Decisão desfeita: {$label} voltou para as chaves a decidir.",
        });
    }
}
