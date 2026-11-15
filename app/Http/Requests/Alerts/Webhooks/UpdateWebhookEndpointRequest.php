<?php

namespace App\Http\Requests\Alerts\Webhooks;

class UpdateWebhookEndpointRequest extends WebhookRequest
{
    use WebhookFormRules;

    public function rules(): array
    {
        return $this->endpointRules(keepUrl: true);
    }
}
