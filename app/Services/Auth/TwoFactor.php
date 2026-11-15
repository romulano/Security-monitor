<?php

namespace App\Services\Auth;

use App\Exceptions\ActionRefused;
use App\Mail\TwoFactorChanged;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Services\Company\CompanyTwoFactor;
use App\Services\Mail\Outbox;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

/**
 * Verificação em duas etapas por app autenticador (TOTP, RFC 6238: 6 dígitos a
 * cada 30 s) e códigos de recuperação. Opcional para toda conta.
 *
 * Ligar tem dois passos: `start` grava um segredo novo, ainda sem valer (a tela
 * mostra o QR e o segredo em texto), e `confirm` só liga com um código certo do
 * app, para ninguém ficar trancado por um app mal configurado. O segredo vai
 * cifrado com a APP_KEY; depois de ligar, ele não aparece mais.
 *
 * Um código vale no passo dele e no vizinho de cada lado (relógio do celular
 * adiantado ou atrasado), e uma vez só: o passo aceito fica gravado, e um código
 * do mesmo passo, ou de um anterior, é recusado, mesmo em duas requisições ao
 * mesmo tempo (a gravação é condicional, no banco).
 *
 * Os códigos de recuperação aparecem uma vez, ao ligar e ao gerar novos; no
 * banco fica só o HMAC de cada um, com a APP_KEY (como o segredo, o banco
 * sozinho não abre nada). No login, entram pelo TwoFactorChallenge.
 */
class TwoFactor
{
    public const RECOVERY_CODES = 10;

    /** Sem 0/O e 1/I/L, para digitar de um papel: 10 caracteres destes 31 são uns 49 bits. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const SECRET_LENGTH = 32;

    private const WINDOW = 1;

    /** A configuração pela metade vence: o segredo que ficou na tela não liga nada depois disso. */
    public const PENDING_MINUTES = 30;

    public function __construct(private Google2FA $totp, private Outbox $outbox) {}

    public static function available(): bool
    {
        return (bool) config('secmon.two_factor.enabled');
    }

    /** A conta tem a 2FA ligada e a chave está ligada: o login e a reautenticação pedem o código. */
    public static function required(User $user): bool
    {
        return self::available() && self::enabled($user);
    }

    /**
     * Confere o código do app ou um de recuperação (com letras), e gasta o que
     * conferir. Devolve qual foi (`app` ou `recovery`) ou nulo.
     */
    public function check(UserTwoFactor $twoFactor, string $code): ?string
    {
        if (preg_match('/[A-Za-z]/', $code) === 1) {
            return $this->useRecoveryCode($twoFactor, $code) ? 'recovery' : null;
        }

        return $this->verify($twoFactor, $code) ? 'app' : null;
    }

    /**
     * O limite dos códigos errados, a cada 15 minutos: 20 por IP, e 5 por conta
     * no login ou 5 por sessão na reautenticação (`$session`, a chave da trava da
     * sessão). Separados de propósito (H2 do Red Team): quem tem só a senha erra
     * códigos no login e não tranca a reautenticação da dona, que segue podendo
     * trocar a senha numa sessão dela. Devolve os segundos até destravar, ou nulo.
     */
    public static function locked(User $user, ?string $ip, ?string $session = null): ?int
    {
        foreach (self::limits($user, $ip, $session) as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return max(1, RateLimiter::availableIn($key));
            }
        }

        return null;
    }

    /**
     * Conta a tentativa ANTES de conferir o código (o hit é atômico no cache) e
     * devolve os segundos de espera se ela passou do limite, ou nulo. Conferir
     * "ainda não chegou a 5" e contar só o erro, depois, deixava N pedidos ao
     * mesmo tempo passarem todos (G1/H3 do Red Team).
     */
    public static function attempt(User $user, ?string $ip, ?string $session = null): ?int
    {
        foreach (self::limits($user, $ip, $session) as $key => $max) {
            if (RateLimiter::hit($key, 900) > $max) {
                return max(1, RateLimiter::availableIn($key));
            }
        }

        return null;
    }

    /** A trava do login da conta fechou (5 códigos errados nos últimos 15 minutos)? */
    public static function accountLocked(User $user): bool
    {
        return RateLimiter::attempts('two-factor:'.$user->id) >= 5;
    }

    /** O código certo não conta: zera a conta (ou a sessão) e devolve a tentativa ao IP (a rede de um escritório não trava). */
    public static function passed(User $user, ?string $ip, ?string $session = null): void
    {
        foreach (array_keys(self::limits($user, $ip, $session)) as $key) {
            if (! str_starts_with($key, 'two-factor-ip:')) {
                RateLimiter::clear($key);
            } elseif (RateLimiter::attempts($key) > 0) {
                RateLimiter::decrement($key, 900);
            }
        }
    }

    /** @return array<string, int> */
    private static function limits(User $user, ?string $ip, ?string $session): array
    {
        $own = $session !== null ? 'two-factor-reauth:'.$user->id.':'.$session : 'two-factor:'.$user->id;

        return [$own => 5] + ($ip ? ['two-factor-ip:'.$ip => 20] : []);
    }

    /** Sempre do banco, não da relação carregada: a mesma conta pode ter mudado nesta requisição. */
    public static function current(User $user): ?UserTwoFactor
    {
        return UserTwoFactor::where('user_id', $user->id)->first();
    }

    public static function enabled(User $user): bool
    {
        return UserTwoFactor::where('user_id', $user->id)->whereNotNull('confirmed_at')->exists();
    }

    /**
     * O que a tela mostra: desligada, pela metade (com o QR para o app) ou
     * ligada (desde quando e quantos códigos de recuperação ainda valem).
     *
     * O QR e o segredo só aparecem com `$showSecret` (a reautenticação recente desta sessão): outra
     * sessão da conta, ou esta depois dos 10 minutos, vê que falta confirmar, não o segredo.
     *
     * @return array{state: 'off'|'pending'|'on', since: ?Carbon, remaining: int, enrollment: ?array{url: string, svg: string, secret: string}}
     */
    public function status(User $user, bool $showSecret = false): array
    {
        $twoFactor = self::current($user);
        $state = $twoFactor === null || $this->expired($twoFactor) ? 'off' : ($twoFactor->confirmed() ? 'on' : 'pending');

        return [
            'state' => $state,
            'since' => $twoFactor?->confirmed_at,
            'remaining' => $state === 'on' ? count($twoFactor->recovery_codes ?? []) : 0,
            'enrollment' => $state === 'pending' && $showSecret ? $this->enrollment($user, $twoFactor) : null,
        ];
    }

    /** Começa (ou recomeça) a configuração com um segredo novo. Com a 2FA ligada, é preciso desligar antes. */
    public function start(User $user): UserTwoFactor
    {
        $values = [
            'secret' => Crypt::encryptString($this->totp->generateSecretKey(self::SECRET_LENGTH)),
            'last_used_step' => null,
            'recovery_codes' => null,
            'updated_at' => now(),
        ];

        // A condição fica no banco: a 2FA ligada nunca é reescrita, nem pela confirmação de outra aba
        // gravada entre a conferência e a gravação (o enabled() antes e o save() depois deixavam a janela).
        $restarted = DB::table('user_two_factor')->where('user_id', $user->id)->whereNull('confirmed_at')->update($values);

        if ($restarted === 0 && DB::table('user_two_factor')->insertOrIgnore([...$values, 'user_id' => $user->id, 'created_at' => now()]) === 0) {
            throw new ActionRefused('A verificação em duas etapas já está ligada. Para trocar de app, desligue e ligue de novo.');
        }

        return self::current($user);
    }

    private function expired(UserTwoFactor $twoFactor): bool
    {
        return ! $twoFactor->confirmed() && $twoFactor->updated_at->lt(now()->subMinutes(self::PENDING_MINUTES));
    }

    /**
     * Para cadastrar o app: o otpauth:// (o link "Abrir no app", no celular), o QR
     * dele (SVG inline, sem script nem imagem externa, o que a CSP aceita) e o
     * segredo em blocos de 4, para digitar à mão.
     *
     * @return array{url: string, svg: string, secret: string}
     */
    private function enrollment(User $user, UserTwoFactor $twoFactor): array
    {
        $url = $this->totp->getQRCodeUrl((string) config('secmon.two_factor.issuer'), $user->email, $twoFactor->secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 2), new SvgImageBackEnd)))->writeString($url);

        return [
            'url' => $url,
            'svg' => trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', $svg)),
            'secret' => trim(chunk_split($twoFactor->secret, 4, ' ')),
        ];
    }

    /**
     * Liga a 2FA com o primeiro código certo do app e devolve os códigos de
     * recuperação, para mostrar uma vez.
     *
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function confirm(User $user, string $code, ?string $keepSession = null): array
    {
        $twoFactor = self::current($user);

        if ($twoFactor === null || $twoFactor->confirmed() || $this->expired($twoFactor)) {
            throw new ActionRefused($twoFactor?->confirmed() ? 'A verificação em duas etapas já está ligada.' : 'Comece a configuração de novo: aperte "Ligar".');
        }

        // O segredo com que o código vai ser conferido: a confirmação só vale se ele não mudou no meio.
        $cipher = $twoFactor->getRawOriginal('secret');

        if (! $this->verify($twoFactor, $code)) {
            throw ValidationException::withMessages(['two_factor_code' => 'O código não confere. Digite o que o app mostra agora. Se continuar errado, confira se a data e a hora do celular estão no automático.'])->errorBag('two_factor');
        }

        $codes = DB::transaction(function () use ($user, $twoFactor, $cipher) {
            // Duas confirmações ao mesmo tempo: só uma liga e mostra os códigos. "Ligar" de novo em outra
            // aba trocou o segredo: não liga com um que o app da pessoa não tem.
            if (UserTwoFactor::whereKey($twoFactor->id)->whereNull('confirmed_at')->where('secret', $cipher)->update(['confirmed_at' => now()]) !== 1) {
                throw new ActionRefused(self::enabled($user) ? 'A verificação em duas etapas já está ligada.' : 'A configuração recomeçou em outra aba: leia o QR novo e digite o código dele.');
            }

            // O "Continuar conectado" de antes da 2FA entraria sem o código: o cookie antigo deixa de valer.
            $user->forceFill(['remember_token' => Str::random(60)])->save();

            return $this->replaceRecoveryCodes($twoFactor);
        });

        // As outras sessões abertas entraram sem o código: saem (esta, `$keepSession`, continua).
        $user->endSessions(except: $keepSession);
        $this->notify($user, 'on');

        return $codes;
    }

    /**
     * O código do app confere com o segredo, no passo atual ou num vizinho, e
     * ainda não foi usado? Se sim, o passo dele fica gravado e não vale de novo.
     */
    public function verify(UserTwoFactor $twoFactor, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{6}$/', (string) $code)) {
            return false;
        }

        $step = $this->totp->verifyKeyNewer($twoFactor->secret, $code, $twoFactor->last_used_step ?? 0, self::WINDOW, intdiv(now()->getTimestamp(), 30));

        if (! is_int($step)) {
            return false;
        }

        // Duas requisições com o mesmo código: só uma grava o passo.
        $accepted = UserTwoFactor::whereKey($twoFactor->id)
            ->where(fn ($query) => $query->whereNull('last_used_step')->orWhere('last_used_step', '<', $step))
            ->update(['last_used_step' => $step]) === 1;

        if ($accepted) {
            $twoFactor->last_used_step = $step;
            $twoFactor->syncOriginalAttribute('last_used_step');
        }

        return $accepted;
    }

    /**
     * Códigos de recuperação novos; os antigos deixam de valer.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $twoFactor = self::current($user);

        // Lida uma vez só: desligar em outra aba entre a conferência e a gravação não vira erro 500.
        if (! $twoFactor?->confirmed()) {
            throw new ActionRefused('A verificação em duas etapas não está ligada.');
        }

        $codes = $this->replaceRecoveryCodes($twoFactor);
        $this->notify($user, 'codes');

        return $codes;
    }

    /** Cancela a configuração ainda não confirmada: não diminui a proteção, então não pede a senha. */
    public function cancel(User $user): void
    {
        UserTwoFactor::where('user_id', $user->id)->whereNull('confirmed_at')->delete();
    }

    /**
     * Desliga a 2FA (ou cancela a configuração pela metade): o segredo e os códigos são apagados. `$event`: o
     * aviso por e-mail (`reset`, quando quem administra a empresa redefine a da conta, D11). Devolve se estava ligada.
     */
    public function disable(User $user, string $event = 'off'): bool
    {
        // A própria conta não desliga a 2FA que a empresa exige (trocar de celular é pela redefinição da admin).
        if ($event === 'off' && CompanyTwoFactor::requires($user) && self::enabled($user)) {
            throw new ActionRefused('A sua empresa exige a verificação em duas etapas, então ela não pode ser desligada. Para trocar de celular, peça a quem administra a conta da empresa para redefinir a sua.');
        }

        $wasOn = UserTwoFactor::where('user_id', $user->id)->whereNotNull('confirmed_at')->delete() > 0;
        UserTwoFactor::where('user_id', $user->id)->delete();

        if ($wasOn) {
            $this->notify($user, $event);
        }

        return $wasOn;
    }

    /** O aviso por e-mail à própria conta: ligar, desligar, gerar códigos novos e a trava do código não passam sem ela saber. */
    public function notify(User $user, string $event): void
    {
        $now = now()->timezone('America/Sao_Paulo');
        $when = $now->format('d/m/Y').' às '.$now->format('H:i');
        $this->outbox->send(new TwoFactorChanged($user->name, $event, $when, route('account.two-factor')), $user->email, 'seguranca', $user);
    }

    /** O hash guardado de um código de recuperação (maiúsculas, sem o hífen nem espaços); `$key`: outra APP_KEY. */
    public static function hashRecoveryCode(string $code, ?string $key = null): string
    {
        return hash_hmac('sha256', strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code)), $key ?? (string) config('app.key'));
    }

    /**
     * Entra com um código de recuperação: se ele está na lista, sai dela, e não
     * vale de novo (a linha fica travada até gravar, então dois pedidos com o
     * mesmo código não passam os dois). Confere também com as chaves anteriores
     * (APP_PREVIOUS_KEYS), para a troca da APP_KEY não apagar os códigos.
     */
    public function useRecoveryCode(UserTwoFactor $twoFactor, string $code): bool
    {
        if (strlen((string) preg_replace('/[^A-Za-z0-9]/', '', $code)) !== 10) {
            return false;
        }

        $keys = array_filter([(string) config('app.key'), ...(array) config('app.previous_keys', [])]);
        $hashes = array_map(fn ($key) => self::hashRecoveryCode($code, $key), $keys);

        return DB::transaction(function () use ($twoFactor, $hashes) {
            $locked = UserTwoFactor::whereKey($twoFactor->id)->whereNotNull('confirmed_at')->lockForUpdate()->first();
            $codes = $locked?->recovery_codes ?? [];

            // Comparação em tempo constante, código a código; o que bater sai da lista na mesma transação.
            foreach ($codes as $i => $stored) {
                foreach ($hashes as $hash) {
                    if (is_string($stored) && hash_equals($stored, $hash)) {
                        unset($codes[$i]);
                        $locked->forceFill(['recovery_codes' => array_values($codes)])->save();

                        return true;
                    }
                }
            }

            return false;
        });
    }

    /** @return list<string> */
    private function replaceRecoveryCodes(UserTwoFactor $twoFactor): array
    {
        $char = fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        $codes = array_map(fn () => implode(array_map($char, range(1, 5))).'-'.implode(array_map($char, range(1, 5))), range(1, self::RECOVERY_CODES));

        $twoFactor->forceFill(['recovery_codes' => array_map(self::hashRecoveryCode(...), $codes)])->save();

        return $codes;
    }
}
