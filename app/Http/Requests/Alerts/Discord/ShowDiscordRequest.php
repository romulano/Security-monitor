<?php

namespace App\Http\Requests\Alerts\Discord;

use App\Http\Requests\Concerns\TargetsChannel;
use App\Http\Requests\Request;

/** A tela do Discord: o canal pessoal, ou o da empresa pelo `?canal=`/`?setor=` (E5a2). */
class ShowDiscordRequest extends Request
{
    use TargetsChannel;

    protected function channelApp(): string
    {
        return 'discord';
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

    /** `?escolher=1`: trocar o canal do servidor já escolhido (era `?canal=1`, que agora é o canal da empresa, E5a2). */
    public function pickChannel(): bool
    {
        return $this->boolean('escolher');
    }
}
