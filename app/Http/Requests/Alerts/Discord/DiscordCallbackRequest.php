<?php

namespace App\Http\Requests\Alerts\Discord;

use App\Http\Requests\Request;
use App\Services\Alerts\Discord\DiscordClient;

class DiscordCallbackRequest extends Request
{
    public function authorize(): bool
    {
        return DiscordClient::configured();
    }

    /** O state que o Discord devolveu, para conferir com o da sessão. */
    public function state(): string
    {
        $state = $this->query('state');

        return is_string($state) ? $state : '';
    }

    /** O código da autorização; nulo quando o cliente cancelou na tela do Discord (`error`) ou ele não veio. */
    public function code(): ?string
    {
        return $this->filled('error') || ! $this->filled('code') ? null : (string) $this->query('code');
    }
}
