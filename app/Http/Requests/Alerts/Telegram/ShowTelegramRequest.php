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

    /**
     * O canal que o bot acabou de conectar numa tela aberta pelo `?setor=` (o grupo respondeu ao /start): o mais novo
     * desse escopo que esta conta conectou. Nulo fora da empresa ou com o `?canal=`.
     */
    public function justConnected(): ?NotificationChannel
    {
        $scope = $this->user()->isCompanyAccount() && $this->channel() === null ? $this->channelScope() : null;

        return $scope === null ? null
            : $scope->constrain(NotificationChannel::query())->where('type', 'telegram')->where('user_id', $this->user()->id)->latest('id')->first();
    }
}
