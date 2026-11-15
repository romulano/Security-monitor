<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ShowAccountRequest;
use App\Services\Auth\Reauthentication;
use App\Services\Auth\TwoFactor;
use Illuminate\View\View;

class ShowAccountController extends Controller
{
    public function __construct(private Reauthentication $reauth) {}

    public function __invoke(ShowAccountRequest $request): View
    {
        return view('account.show', [
            'user' => $request->user(),
            'reauth' => $this->reauth->options($request->user(), $request->session()),
            // Nulo com TWO_FACTOR_ENABLED desligada: a seção nem aparece.
            'twoFactor' => TwoFactor::available() ? TwoFactor::enabled($request->user()) : null,
        ]);
    }
}
