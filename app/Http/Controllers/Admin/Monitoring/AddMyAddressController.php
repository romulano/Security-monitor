<?php

namespace App\Http\Controllers\Admin\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Monitoring\AddMyAddressRequest;
use App\Services\Monitoring\OwnAddresses;
use Illuminate\Http\RedirectResponse;

/** Põe o IP de quem está no admin na lista de nossos (o de casa do dono muda). */
class AddMyAddressController extends Controller
{
    public function __construct(private OwnAddresses $own) {}

    public function __invoke(AddMyAddressRequest $request): RedirectResponse
    {
        $ip = $request->address();

        return back()->with('status', $this->own->add($ip)
            ? "{$ip} entrou nos IPs nossos: os acessos dele, de agora e de antes, contam como nossos."
            : "{$ip} já conta como nosso.");
    }
}
