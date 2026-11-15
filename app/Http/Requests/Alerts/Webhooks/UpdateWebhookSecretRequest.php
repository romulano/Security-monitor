<?php

namespace App\Http\Requests\Alerts\Webhooks;

class UpdateWebhookSecretRequest extends WebhookRequest
{
    use WebhookFormRules;

    public function rules(): array
    {
        return $this->authRules();
    }
}
