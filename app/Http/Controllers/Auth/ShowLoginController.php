<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\GithubOAuth;
use Illuminate\View\View;

class ShowLoginController extends Controller
{
    public function __invoke(): View
    {
        return view('auth.login', ['admin' => false, 'github' => GithubOAuth::configured()]);
    }
}
