<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\ToggleAbuseReportRequest;
use App\Models\LookalikeDomain;
use App\Services\Lookalike\AbuseKit;
use Illuminate\Http\RedirectResponse;

/** "Enviei" num caminho do kit (ou desfaz): o Radar passa a olhar o domínio todo dia até ele cair. */
class ToggleAbuseReportController extends Controller
{
    public function __construct(private AbuseKit $kit) {}

    public function __invoke(ToggleAbuseReportRequest $request, LookalikeDomain $lookalike, string $canal): RedirectResponse
    {
        $sent = $this->kit->toggleSent($lookalike, $canal);

        return back()->with('status', $sent
            ? 'Anotado: denúncia enviada para '.AbuseKit::CHANNELS[$canal].'. O Radar abre o domínio todo dia e avisa quando ele cair.'
            : 'Desmarcado: '.AbuseKit::CHANNELS[$canal].'.');
    }
}
