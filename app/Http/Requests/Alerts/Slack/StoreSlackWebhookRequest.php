<?php

namespace App\Http\Requests\Alerts\Slack;

use App\Http\Requests\Concerns\TargetsChannel;
use App\Http\Requests\Request;
use App\Services\Alerts\Slack\SlackClient;

class StoreSlackWebhookRequest extends Request
{
    use TargetsChannel;

    protected function channelApp(): string
    {
        return 'slack';
    }

    public function authorize(): bool
    {
        return $this->targetsManagedChannel();
    }

    public function rules(): array
    {
        return [
            'webhook_url' => ['required', 'string', 'max:300', function ($attribute, $value, $fail) {
                if (! SlackClient::isWebhookUrl(trim((string) $value))) {
                    $fail('Use a URL de um Incoming Webhook (https://hooks.slack.com/services/…) ou do webhook de um fluxo de trabalho do Slack (https://hooks.slack.com/triggers/…).');
                }
            }],
        ];
    }

    public function webhookUrl(): string
    {
        return trim($this->validated('webhook_url'));
    }
}
