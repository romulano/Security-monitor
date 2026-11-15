<?php

namespace App\Http\Controllers\Alerts\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Webhooks\UpdateWebhookRequest;
use App\Services\Alerts\ChannelSettings;
use Illuminate\Http\RedirectResponse;

class UpdateWebhookController extends Controller
{
    public function __construct(private ChannelSettings $settings) {}

    public function __invoke(UpdateWebhookRequest $request): RedirectResponse
    {
        $this->settings->save($request->channel(), $request->preferences());

        return back()->with('status', 'Preferências salvas.');
    }
}
