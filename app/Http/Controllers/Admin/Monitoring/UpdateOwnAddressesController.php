<?php

namespace App\Http\Controllers\Admin\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Monitoring\UpdateOwnAddressesRequest;
use App\Services\Monitoring\OwnAddresses;
use Illuminate\Http\RedirectResponse;

/** Troca a lista de IPs "nossos" da tela de acessos (os do servidor e a rede interna já contam sozinhos). */
class UpdateOwnAddressesController extends Controller
{
    public function __construct(private OwnAddresses $own) {}

    public function __invoke(UpdateOwnAddressesRequest $request): RedirectResponse
    {
        $count = $this->own->save($request->entries());

        return back()->with('status', $count === 0
            ? 'Lista de IPs nossos vazia: contam como nossos só a rede interna e os endereços do servidor.'
            : "{$count} IP(s) ou faixa(s) na lista de nossos: os acessos deles, de agora e de antes, contam como nossos.");
    }
}
