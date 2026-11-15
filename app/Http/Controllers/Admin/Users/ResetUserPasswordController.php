<?php

namespace App\Http\Controllers\Admin\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\ResetUserPasswordRequest;
use App\Models\User;
use App\Services\Admin\UserManager;
use App\Services\Company\CompanyAudit;
use Illuminate\Http\RedirectResponse;

class ResetUserPasswordController extends Controller
{
    public function __construct(private UserManager $users, private CompanyAudit $audit) {}

    public function __invoke(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $password = $this->users->resetPassword($user, $request->user(), $request->session()->getId());
        $this->audit->recordForAccount($user, $request->user(), 'account.password');

        return redirect()->route('admin.users')->with('new_password', [
            'name' => $user->name,
            'email' => $user->email,
            'self' => $user->is($request->user()),
            'password' => $password,
        ]);
    }
}
