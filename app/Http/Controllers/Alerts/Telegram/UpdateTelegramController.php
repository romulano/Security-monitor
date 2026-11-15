<?php

namespace App\Http\Controllers\Alerts\Telegram;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Telegram\UpdateTelegramRequest;
use App\Services\Alerts\ChannelSettings;
use Illuminate\Http\RedirectResponse;

class UpdateTelegramController extends Controller
{
    public function __construct(private ChannelSettings $settings) {}

    public function __invoke(UpdateTelegramRequest $request): RedirectResponse
    {
        $this->settings->save($request->channel(), $request->preferences());

        return back()->with('status', 'Preferências salvas.');
    }
}
