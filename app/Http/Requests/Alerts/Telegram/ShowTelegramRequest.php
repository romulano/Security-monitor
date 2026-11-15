<?php

namespace App\Http\Requests\Alerts\Telegram;

use App\Http\Requests\Concerns\TargetsChannel;
use App\Http\Requests\Request;
use App\Models\NotificationChannel;

/** A tela do Telegram: o canal pessoal, ou o da empresa pelo `?canal=`/`?setor=` (E5a2). */
class ShowTelegramRequest extends Request
{
    use TargetsChannel;

    protected function channelApp(): string
    {
        return 'telegram';
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

    /** Existia para a conta de empresa, que podia ter vários canais do Telegram; com um canal por app, não há o que descobrir. */
    public function justConnected(): ?NotificationChannel
    {
        return null;
    }
}
