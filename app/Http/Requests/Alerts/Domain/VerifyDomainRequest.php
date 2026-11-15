<?php

namespace App\Http\Requests\Alerts\Domain;

class VerifyDomainRequest extends DomainRequest
{
    /** Sem domínio com aceite, não há o que verificar: 404. */
    public function authorize(): bool
    {
        return (bool) $this->domain()?->hasConsent();
    }
}
