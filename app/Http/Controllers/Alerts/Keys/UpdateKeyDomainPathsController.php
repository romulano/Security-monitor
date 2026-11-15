<?php

namespace App\Http\Controllers\Alerts\Keys;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Keys\UpdateKeyDomainPathsRequest;
use App\Services\Keys\KeySites;
use Illuminate\Http\RedirectResponse;

/** Os endereços extras que a varredura de chaves do domínio sempre visita. */
class UpdateKeyDomainPathsController extends Controller
{
    public function __construct(private KeySites $sites) {}

    public function __invoke(UpdateKeyDomainPathsRequest $request): RedirectResponse
    {
        $paths = $request->paths();
        $this->sites->setPaths($request->owned(), $request->user(), $paths);

        return back()->with('status', $paths === [] ? 'Sem endereços extras: a varredura segue os links, os sitemaps e o Web Archive.'
            : count($paths).(count($paths) === 1 ? ' endereço extra salvo' : ' endereços extras salvos').': a próxima varredura visita '.(count($paths) === 1 ? 'ele' : 'todos').' primeiro.');
    }
}
