<?php

namespace App\Http\Requests\Alerts\Webhooks;

use App\Http\Requests\Request;

class StoreWebhookRequest extends Request
{
    use WebhookFormRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->endpointRules() + $this->authRules();
    }
}
