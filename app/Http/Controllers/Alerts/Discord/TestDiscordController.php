<?php

namespace App\Http\Controllers\Alerts\Discord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Discord\TestDiscordRequest;
use App\Services\Alerts\Discord\DiscordConnector;
use Illuminate\Http\RedirectResponse;

class TestDiscordController extends Controller
{
    public function __construct(private DiscordConnector $discord) {}

    public function __invoke(TestDiscordRequest $request): RedirectResponse
    {
        $this->discord->test($request->channel());

        return back()->with('status', 'Mensagem de teste enviada. Se não aparecer no Discord em alguns segundos, veja os últimos envios abaixo.');
    }
}
