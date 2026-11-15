<?php

namespace App\Http\Requests\Alerts\Discord;

use App\Http\Requests\Concerns\TargetsChannel;
use App\Http\Requests\Request;

class SelectDiscordChannelRequest extends Request
{
    use TargetsChannel;

    protected function channelApp(): string
    {
        return 'discord';
    }

    /** Só quem conectou o bot a um servidor escolhe o canal. */
    public function authorize(): bool
    {
        $channel = $this->channel();

        return $channel && $channel->method === 'channel' && $channel->discord_guild_id;
    }

    public function rules(): array
    {
        return [
            'channel_id' => ['required', 'string', 'regex:/^[0-9]{15,22}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'channel_id.*' => 'Escolha um canal da lista.',
        ];
    }

    public function channelId(): string
    {
        return $this->validated('channel_id');
    }
}
