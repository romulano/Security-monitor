<?php

namespace App\Services\Auth;

use App\Services\Monitoring\AccessEventRecorder;
use Illuminate\Http\Request;

/**
 * As tentativas recusadas de login e cadastro (a /privacidade promete o
 * registro): senha errada, excesso de tentativas, captcha recusado e e-mail
 * já cadastrado. A requisição é marcada aqui e o evento é gravado no terminate
 * (AccessEventRecorder), com a resposta que saiu, o IP, o navegador e o mesmo
 * teto por minuto dos outros eventos, mesmo com a monitoria desligada.
 *
 * Do e-mail digitado fica só um código (HMAC com a APP_KEY): conta as
 * tentativas contra a mesma conta e as contas tentadas por um IP sem guardar o
 * e-mail. A senha nunca passa por aqui.
 */
class RejectedAttempts
{
    /** Os motivos, no `auth` do evento. */
    public const REASONS = [
        'login_senha' => 'senha errada',
        'login_trava' => 'excesso de tentativas',
        'admin_senha' => 'senha errada no admin',
        'admin_trava' => 'excesso de tentativas no admin',
        'login_codigo' => 'código da verificação em duas etapas errado',
        'login_codigo_trava' => 'excesso de tentativas do código',
        'admin_codigo' => 'código da verificação em duas etapas errado no admin',
        'admin_codigo_trava' => 'excesso de tentativas do código no admin',
        'captcha' => 'captcha recusado',
        'cadastro_email_existente' => 'e-mail já cadastrado',
    ];

    public function record(Request $request, string $auth, ?string $email = null): void
    {
        // O form request é uma cópia da requisição: a marca vai também na que o terminate recebe.
        foreach ([$request, request()] as $marked) {
            $marked->attributes->set(AccessEventRecorder::ATTEMPT, ['auth' => $auth, 'account_hmac' => self::hmac($email)]);
        }
    }

    /** O código do e-mail: o mesmo para `A@x.com` e ` a@x.com `; nulo sem e-mail. */
    public static function hmac(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : substr(hash_hmac('sha256', $email, 'secmon:tentativas:'.config('app.key')), 0, 32);
    }
}
