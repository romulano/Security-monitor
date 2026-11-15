<?php

namespace App\Http\Controllers\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\ShowNotifiersRequest;
use App\Services\Alerts\AlertCenter;
use Illuminate\View\View;

/**
 * /alertas/notificadores: por qual app (Slack, Discord, Telegram) o cliente recebe os alertas e o que cada um recebe.
 * A conta de empresa vê os canais da empresa por escopo (gerais e setores, E5a2); a pessoal, a tela de sempre.
 */
class ShowNotifiersController extends Controller
{
    public function __construct(private AlertCenter $center) {}

    public function __invoke(ShowNotifiersRequest $request): View
    {
        $user = $request->user();

        return $user->isCompanyAccount()
            ? view('alerts.notifiers-company', $this->center->companyPage($user))
            : view('alerts.notifiers', $this->center->page($user));
    }
}
