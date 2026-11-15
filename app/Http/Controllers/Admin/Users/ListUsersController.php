<?php

namespace App\Http\Controllers\Admin\Users;

use App\Http\Controllers\Controller;
use App\Services\Admin\UserManager;
use Illuminate\View\View;

class ListUsersController extends Controller
{
    public function __construct(private UserManager $users) {}

    public function __invoke(): View
    {
        return view('admin.users', ['users' => $this->users->list(), 'pentestRequests' => $this->users->pentestRequests()]);
    }
}
