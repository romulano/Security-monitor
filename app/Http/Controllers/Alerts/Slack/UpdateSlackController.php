<?php

namespace App\Http\Controllers\Alerts\Slack;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Slack\UpdateSlackRequest;
use App\Services\Alerts\ChannelSettings;
use Illuminate\Http\RedirectResponse;

class UpdateSlackController extends Controller
{
    public function __construct(private ChannelSettings $settings) {}

    public function __invoke(UpdateSlackRequest $request): RedirectResponse
    {
        $this->settings->save($request->channel(), $request->preferences());

        return back()->with('status', 'Preferências salvas.');
    }
}
