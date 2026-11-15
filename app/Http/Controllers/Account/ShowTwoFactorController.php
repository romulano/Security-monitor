<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTwoFactorWhenRequired;
use App\Http\Requests\Account\ShowTwoFactorRequest;
use App\Services\Auth\Reauthentication;
use App\Services\Auth\TwoFactor;
use App\Services\Company\CompanyTwoFactor;
use Illuminate\Http\Response;

class ShowTwoFactorController extends Controller
{
    public function __construct(private TwoFactor $twoFactor, private Reauthentication $reauth) {}

    public function __invoke(ShowTwoFactorRequest $request): Response
    {
        $reauth = $this->reauth->options($request->user(), $request->session());

        // O segredo e os códigos de recuperação não ficam no cache do navegador (voltar, disco).
        return response()->view('account.two-factor', [
            'status' => $this->twoFactor->status($request->user(), showSecret: $reauth['recent']),
            // Os códigos de recuperação chegam cifrados na sessão, só na volta de ligar ou de gerar novos.
            'codes' => $request->session()->has('recovery_codes') ? decrypt($request->session()->get('recovery_codes')) : [],
            'reauth' => $reauth,
            // A 2FA exigida (empresa ou admin) ainda bloqueia o resto do site; e a da empresa não se desliga.
            'blocked' => EnsureTwoFactorWhenRequired::missing($request->user()),
            'companyRequires' => CompanyTwoFactor::requires($request->user()),
            // Quem veio do admin (TWO_FACTOR_ADMIN_REQUIRED) volta para ele depois de ligar.
            'next' => str_starts_with($next = (string) $request->session()->get('url.intended'), url('/admin')) ? $next : null,
        ])->header('Cache-Control', 'no-store, private');
    }
}
