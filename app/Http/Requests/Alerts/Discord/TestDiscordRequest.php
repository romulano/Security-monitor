<?php

namespace App\Http\Requests\Alerts\Discord;

use App\Http\Requests\Concerns\TargetsChannel;
use App\Http\Requests\Request;

class TestDiscordRequest extends Request
{
    use TargetsChannel;

    protected function channelApp(): string
    {
        return 'discord';
    }

    public function authorize(): bool
    {
        return $this->channel() !== null;
    }
}
