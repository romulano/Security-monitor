<?php

namespace App\Http\Controllers\Alerts;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/** "Ler no Security Monitor" da mensagem do Slack: leva à matéria pelo /a/, onde a prévia do Slack não entra. */
class ReadAlertCaseController extends Controller
{
    public function __invoke(string $uuid): RedirectResponse
    {
        return redirect()->route('feed.show', $uuid);
    }
}
