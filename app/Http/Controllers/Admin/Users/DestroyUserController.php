<?php

namespace App\Http\Controllers\Admin\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\DestroyUserRequest;
use App\Models\User;
use App\Services\Admin\UserRemoval;
use Illuminate\Http\RedirectResponse;

class DestroyUserController extends Controller
{
    public function __construct(private UserRemoval $removal) {}

    public function __invoke(DestroyUserRequest $request, User $user): RedirectResponse
    {
        $this->removal->remove($user, $request->user());

        return redirect()->route('admin.users')->with('status', "A conta de {$user->name} foi removida e não tem mais acesso ao Security Monitor.");
    }
}
