<?php

namespace App\Http\Requests\Alerts\Slack;

use App\Http\Requests\Request;
use App\Services\Alerts\Slack\SlackClient;

class SlackCallbackRequest extends Request
{
    public function authorize(): bool
    {
        return SlackClient::oauthConfigured();
    }

    /** O state que o Slack devolveu, para conferir com o da sessão. */
    public function state(): string
    {
        $state = $this->query('state');

        return is_string($state) ? $state : '';
    }

    /** O código da instalação; nulo quando o cliente cancelou na tela do Slack (`error`) ou ele não veio. */
    public function code(): ?string
    {
        return $this->filled('error') || ! $this->filled('code') ? null : (string) $this->query('code');
    }
}
