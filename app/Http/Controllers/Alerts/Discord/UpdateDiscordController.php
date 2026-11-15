<?php

namespace App\Http\Controllers\Alerts\Discord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Discord\UpdateDiscordRequest;
use App\Services\Alerts\ChannelSettings;
use Illuminate\Http\RedirectResponse;

class UpdateDiscordController extends Controller
{
    public function __construct(private ChannelSettings $settings) {}

    public function __invoke(UpdateDiscordRequest $request): RedirectResponse
    {
        $this->settings->save($request->channel(), $request->preferences());

        return back()->with('status', 'Preferências salvas.');
    }
}
