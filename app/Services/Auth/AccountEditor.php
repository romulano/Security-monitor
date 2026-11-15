<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Área do usuário comum: só nome, e-mail e senha. A senha atual é conferida
 * pela reautenticação, com o mesmo limite de tentativas da ação sensível.
 */
class AccountEditor
{
    public function __construct(private Reauthentication $reauth, private EmailVerification $verification) {}

    /**
     * Trocar o e-mail pede a senha atual ou a reautenticação recente (GitHub ou
     * código num app). Com a sessão sozinha, quem a roubou trocaria o e-mail de
     * entrada e, por ele, ligaria o próprio GitHub à conta: o primeiro login com
     * o GitHub liga pelo e-mail verificado. Trocar só o nome não pede nada.
     * O e-mail novo precisa ser confirmado de novo (link enviado a ele); até lá,
     * os alertas ficam fechados, como numa conta nova.
     *
     * @param  array{name: string, email: string}  $data
     */
    public function updateProfile(User $user, array $data, ?string $password, Session $session, ?string $code = null): void
    {
        $changed = $data['email'] !== $user->email;

        // Com a 2FA, também o código do app (a caixa "Falta o código do app" logo abaixo do formulário).
        if ($changed) {
            $this->reauth->ensure($user, $session, null, $password, $code, field: 'current_password', bag: 'profile',
                missing: 'Para trocar o e-mail, digite a sua senha atual.');
        }

        $user->fill($data);

        if ($changed) {
            $user->forceFill(['email_verified_at' => null]);
        }

        $user->save();

        if ($changed) {
            $this->verification->send($user);
        }
    }

    /**
     * A senha nova derruba as outras sessões e o "continuar conectado" dos
     * outros aparelhos, como na troca pelo admin: trocar a senha é o que a
     * pessoa faz quando desconfia que alguém entrou na conta (e é o que a
     * mensagem do código de confirmação manda fazer). Esta sessão continua.
     */
    public function updatePassword(User $user, string $current, string $password, Session $session, ?string $code = null, ?string $ip = null): void
    {
        // A senha atual sempre; com a 2FA, também o código do app (H1 do Red Team: com a sessão roubada e a
        // senha, trocar a senha derrubava as outras sessões da dona e a deixava de fora sem o código). A
        // confirmação completa (com o código) dos últimos 10 minutos dispensa digitar o código de novo.
        $confirmed = Reauthentication::recent($user, $session);
        $this->reauth->confirmPassword($user, $current, $session, field: 'current_password', bag: 'password');

        if (! $confirmed && TwoFactor::required($user)) {
            $this->reauth->confirmTwoFactor($user, $session, $code, $ip);
        }

        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        $user->endSessions(except: $session->getId());
    }
}
