<?php

namespace App\Http\Requests\Alerts\Domain;

class RerunDomainWallsRequest extends DomainRequest
{
    /** Só há o que reler num domínio que pode ser lido e teve páginas barradas: senão, 404. */
    public function authorize(): bool
    {
        $domain = $this->domain();

        return $domain && $domain->canDiscover() && $domain->walls() !== [];
    }
}
