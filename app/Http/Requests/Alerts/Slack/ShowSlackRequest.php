<?php

namespace App\Http\Requests\Alerts\Slack;

use App\Http\Requests\Concerns\TargetsChannel;
use App\Http\Requests\Request;

/** A tela do Slack: o canal pessoal, ou o da empresa pelo `?canal=`/`?setor=` (E5a2). */
class ShowSlackRequest extends Request
{
    use TargetsChannel;

    protected function channelApp(): string
    {
        return 'slack';
    }

    public function authorize(): bool
    {
        return $this->targetsManagedChannel();
    }

    /** `?trocar=1`: mostra de novo as opções de conexão com o canal já conectado. */
    public function changing(): bool
    {
        return $this->boolean('trocar');
    }
}
