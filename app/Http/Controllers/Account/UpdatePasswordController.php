<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Services\Auth\AccountEditor;
use Illuminate\Http\RedirectResponse;

class UpdatePasswordController extends Controller
{
    public function __construct(private AccountEditor $account) {}

    public function __invoke(UpdatePasswordRequest $request): RedirectResponse
    {
        $this->account->updatePassword($request->user(), $request->validated('current_password'), $request->validated('password'), $request->session(), $request->twoFactorCode(), $request->ip());

        return back()->with('status', 'Senha alterada.');
    }
}
