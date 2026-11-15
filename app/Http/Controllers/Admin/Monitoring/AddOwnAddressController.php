<?php

namespace App\Http\Controllers\Admin\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Monitoring\AddOwnAddressRequest;
use App\Services\Monitoring\OwnAddresses;
use Illuminate\Http\RedirectResponse;

/** "Contar como nosso" na ficha do IP: põe o IP na lista de nossos e volta para a ficha. */
class AddOwnAddressController extends Controller
{
    public function __construct(private OwnAddresses $own) {}

    public function __invoke(AddOwnAddressRequest $request): RedirectResponse
    {
        $ip = $request->address();

        return redirect()->route('admin.accesses.ip', ['ip' => $ip])->with('status', $this->own->add($ip)
            ? "{$ip} entrou nos IPs nossos: os acessos dele, de agora e de antes, contam como nossos."
            : "{$ip} já conta como nosso.");
    }
}
