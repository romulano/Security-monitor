<?php

namespace App\Http\Requests\Alerts\Telegram;

use App\Http\Requests\Concerns\TargetsChannel;
use App\Http\Requests\Request;

class TestTelegramRequest extends Request
{
    use TargetsChannel;

    protected function channelApp(): string
    {
        return 'telegram';
    }

    public function authorize(): bool
    {
        return $this->channel() !== null;
    }
}
