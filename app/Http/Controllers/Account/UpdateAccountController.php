<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Services\Auth\AccountEditor;
use Illuminate\Http\RedirectResponse;

class UpdateAccountController extends Controller
{
    public function __construct(private AccountEditor $account) {}

    public function __invoke(UpdateAccountRequest $request): RedirectResponse
    {
        $this->account->updateProfile($request->user(), $request->profile(), $request->currentPassword(), $request->session(), $request->twoFactorCode());

        return back()->with('status', 'Dados atualizados.');
    }
}
