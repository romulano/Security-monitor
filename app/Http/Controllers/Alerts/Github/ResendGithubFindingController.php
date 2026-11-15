<?php

namespace App\Http\Controllers\Alerts\Github;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Github\ResendGithubFindingRequest;
use App\Models\GithubFinding;
use App\Services\Github\GithubPanel;
use Illuminate\Http\RedirectResponse;

/** "Enviar" (o que a IA segurou ou ficou abaixo do nível) e "Reenviar" um achado. */
class ResendGithubFindingController extends Controller
{
    public function __construct(private GithubPanel $panel) {}

    public function __invoke(ResendGithubFindingRequest $request, GithubFinding $finding): RedirectResponse
    {
        $apps = $this->panel->resend($finding);

        return back()->with('status', 'Enviado para o '.$apps->map->appName()->join(', o ', ' e o ').'.');
    }
}
