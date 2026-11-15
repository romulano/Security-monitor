<?php

namespace App\Http\Requests\Alerts\Webhooks;

use App\Http\Requests\Concerns\ChoosesChannelScope;
use App\Http\Requests\Request;

class StoreWebhookRequest extends Request
{
    use ChoosesChannelScope, WebhookFormRules;

    /** Na conta de empresa, o campo `setor` (o da tela de cadastro) diz onde o webhook recebe; sem ele, 404 (E5a2). */
    public function authorize(): bool
    {
        return $this->choosesManagedScope();
    }

    public function rules(): array
    {
        return $this->endpointRules() + $this->authRules();
    }
}
