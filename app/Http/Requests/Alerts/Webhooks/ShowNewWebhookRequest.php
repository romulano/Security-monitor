<?php

namespace App\Http\Requests\Alerts\Webhooks;

use App\Http\Requests\Concerns\ChoosesChannelScope;
use App\Http\Requests\Request;

/** /alertas/webhooks/novo; na conta de empresa, `?setor=geral|{id}` diz onde o webhook recebe (E5a2). */
class ShowNewWebhookRequest extends Request
{
    use ChoosesChannelScope;

    public function authorize(): bool
    {
        return $this->choosesManagedScope();
    }
}
