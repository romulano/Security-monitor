<?php

namespace App\Http\Controllers\Admin\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Monitoring\ShowAccessSettingsRequest;
use App\Services\Monitoring\OwnAddresses;
use App\Services\Monitoring\PageViewRecorder;
use Illuminate\View\View;

/** Admin → Acessos, aba Ajustes: a chave da monitoria e a lista de IPs nossos. */
class ShowAccessSettingsController extends Controller
{
    public function __construct(private PageViewRecorder $views, private OwnAddresses $own) {}

    public function __invoke(ShowAccessSettingsRequest $request): View
    {
        return view('admin.accesses.settings', [
            'enabled' => $this->views->enabled(),
            'own' => ['internal' => OwnAddresses::INTERNAL, 'server' => $this->own->server(), 'edited' => $this->own->edited()],
        ]);
    }
}
