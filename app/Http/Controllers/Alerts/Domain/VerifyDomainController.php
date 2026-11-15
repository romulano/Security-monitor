<?php

namespace App\Http\Controllers\Alerts\Domain;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Domain\VerifyDomainRequest;
use App\Services\Domains\DomainOwnership;
use Illuminate\Http\RedirectResponse;

/** Prova de posse: o cliente pede para procurarmos o TXT no domínio (a posse vale para a conta inteira). */
class VerifyDomainController extends Controller
{
    public function __construct(private DomainOwnership $ownership) {}

    public function __invoke(VerifyDomainRequest $request): RedirectResponse
    {
        $this->ownership->confirmRow($request->domain(), 'news');

        return back()->with('status', 'Domínio verificado. A descoberta já está na fila e os achados aparecem aqui.');
    }
}
