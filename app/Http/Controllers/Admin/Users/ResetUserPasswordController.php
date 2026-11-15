<?php

namespace App\Http\Controllers\Admin\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\ResetUserPasswordRequest;
use App\Models\User;
use App\Services\Admin\UserManager;
use Illuminate\Http\RedirectResponse;

class ResetUserPasswordController extends Controller
{
    public function __construct(private UserManager $users) {}

    public function __invoke(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $password = $this->users->resetPassword($user, $request->user(), $request->session()->getId());

        return redirect()->route('admin.users')->with('new_password', [
            'name' => $user->name,
            'email' => $user->email,
            'self' => $user->is($request->user()),
            'password' => $password,
        ]);
    }
}
