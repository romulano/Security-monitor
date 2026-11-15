<?php

namespace App\Http\Requests\Alerts\Domain;

use App\Http\Requests\Concerns\TargetsAlertProfile;
use App\Http\Requests\Request;
use App\Models\MonitoredDomain;

/** Ações sobre o domínio de um alerta do cliente: `?alerta=ID` diz qual; sem ele, o primeiro. */
abstract class DomainRequest extends Request
{
    use TargetsAlertProfile;

    private ?MonitoredDomain $domain = null;

    /** O domínio do alerta (404 se a conta não configura o alerta); nulo se o alerta não tem domínio. */
    public function domain(): ?MonitoredDomain
    {
        return $this->domain ??= $this->alertProfileOf($this->user())?->domains()->first();
    }
}
