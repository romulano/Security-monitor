<?php

namespace App\Http\Requests\Alerts\Webhooks;

use App\Http\Requests\Request;

/** /alertas/webhooks/novo. */
class ShowNewWebhookRequest extends Request
{
    public function authorize(): bool
    {
        return true;
    }
}
