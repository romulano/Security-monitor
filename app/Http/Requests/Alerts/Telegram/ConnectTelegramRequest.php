<?php

namespace App\Http\Requests\Alerts\Telegram;

use App\Http\Requests\Concerns\TargetsChannel;
use App\Http\Requests\Request;
use App\Services\Alerts\Telegram\TelegramClient;

class ConnectTelegramRequest extends Request
{
    use TargetsChannel;

    protected function channelApp(): string
    {
        return 'telegram';
    }

    /** Sem o bot configurado não há link para gerar; na empresa, só onde a conta configura. */
    public function authorize(): bool
    {
        return TelegramClient::configured() && $this->targetsManagedChannel();
    }

    public function rules(): array
    {
        return [
            'modo' => ['required', 'in:privado,grupo'],
        ];
    }

    /** "Num grupo" (`grupo`) ou "Numa conversa privada" (`privado`). */
    public function group(): bool
    {
        return $this->validated('modo') === 'grupo';
    }
}
