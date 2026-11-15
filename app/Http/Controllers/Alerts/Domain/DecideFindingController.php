<?php

namespace App\Http\Controllers\Alerts\Domain;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Domain\DecideFindingRequest;
use App\Models\DomainFinding;
use App\Services\Alerts\Discovery\DiscoveryService;
use Illuminate\Http\RedirectResponse;

/** A descoberta já coloca no perfil o que reconhece; aqui o cliente só desfaz ou refaz. */
class DecideFindingController extends Controller
{
    public function __construct(private DiscoveryService $discovery) {}

    public function __invoke(DecideFindingRequest $request, DomainFinding $finding): RedirectResponse
    {
        if ($request->accepting()) {
            $this->discovery->acceptFinding($finding);

            return back()->with('status', "{$finding->label} voltou para o perfil.");
        }

        $this->discovery->ignoreFinding($finding);

        return back()->with('status', "{$finding->label} removido do perfil. As próximas descobertas não o adicionam de novo.");
    }
}
