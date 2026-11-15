<?php

namespace App\Http\Requests\Alerts\Discord;

use App\Http\Requests\Concerns\TargetsChannel;
use App\Http\Requests\Request;
use App\Services\Alerts\Discord\DiscordClient;

class StoreDiscordWebhookRequest extends Request
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

    public function rules(): array
    {
        return [
            'webhook_url' => ['required', 'string', 'max:300', function ($attribute, $value, $fail) {
                if (! DiscordClient::isWebhookUrl(trim((string) $value))) {
                    $fail('Use a URL de um webhook do Discord (https://discord.com/api/webhooks/…).');
                }
            }],
        ];
    }

    public function webhookUrl(): string
    {
        return trim($this->validated('webhook_url'));
    }
}
