<?php

namespace App\Http\Requests\Admin\Alerts\Domains;

use App\Http\Requests\Request;

class ListDomainsRequest extends Request
{
    /** `?log=ID`: o domínio cujo log da última execução abre na página. */
    public function logId(): ?int
    {
        return $this->integer('log') ?: null;
    }
}
