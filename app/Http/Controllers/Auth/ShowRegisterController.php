<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ShowRegisterRequest;
use App\Services\Auth\GithubOAuth;
use Illuminate\View\View;

class ShowRegisterController extends Controller
{
    public function __invoke(ShowRegisterRequest $request): View
    {
        return view('auth.register', ['github' => GithubOAuth::configured()]);
    }
}
