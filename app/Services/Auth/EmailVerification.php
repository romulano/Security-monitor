<?php

namespace App\Services\Auth;

use App\Mail\VerifyEmail;
use App\Models\User;
use App\Services\Mail\Outbox;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Confirmação do e-mail: a conta nova (e a que troca de e-mail) recebe um link
 * assinado, que vale por uma hora; até abrir, os alertas ficam fechados (middleware
 * `verified`). Quem entra com o GitHub já chega confirmado: o GitHub só entrega
 * e-mail verificado. O link é de uso único: com o e-mail já confirmado, ele só
 * avisa que já estava (a trava na linha segura dois cliques ao mesmo tempo).
 */
class EmailVerification
{
    public const LINK_MINUTES = 60;

    public function __construct(private Outbox $outbox) {}

    public function send(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(self::LINK_MINUTES), [
            'user' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->outbox->send(new VerifyEmail($user->name, $url, self::LINK_MINUTES), $user->email, 'confirmacao', $user);
    }

    /** @return bool se a confirmação é nova (false: já estava confirmado) */
    public function verify(User $user): bool
    {
        $new = DB::transaction(function () use ($user) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            return ! $locked->hasVerifiedEmail() && $locked->markEmailAsVerified();
        });

        $user->refresh();

        if ($new) {
            event(new Verified($user));
        }

        return $new;
    }
}
