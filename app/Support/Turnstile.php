<?php

namespace App\Support;

use App\Services\Auth\RejectedAttempts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Captcha da Cloudflare (Turnstile) no login, no login do admin e no cadastro.
 * Liga sozinho quando a site key está no .env (produção); sem ela as telas
 * funcionam como antes (dev e testes). Ligado, é obrigatório: sem token válido
 * a senha nem chega a ser conferida, e se a Cloudflare não responder — ou se
 * a secret key faltar no .env — a porta fica fechada. O token recusado fica
 * registrado (RejectedAttempts); a falha do servidor ou da Cloudflare, não.
 */
class Turnstile
{
    public const FIELD = 'cf-turnstile-response';

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** A site key liga o captcha; a secret sozinha não (não há widget para resolver). */
    public static function enabled(): bool
    {
        return filled(config('services.turnstile.site_key'));
    }

    public static function siteKey(): string
    {
        return (string) config('services.turnstile.site_key');
    }

    /**
     * O `action` amarra o token à tela que o gerou: um token resolvido no
     * cadastro não serve no login do admin.
     */
    public static function verify(Request $request, string $action): void
    {
        if (! self::enabled()) {
            return;
        }

        $token = $request->input(self::FIELD);

        // A Cloudflare documenta tokens de até 2048 caracteres.
        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            self::refuse($request);
        }

        if (blank(config('services.turnstile.secret_key'))) {
            Log::error('turnstile: TURNSTILE_SITE_KEY sem TURNSTILE_SECRET_KEY no .env; login e cadastro bloqueados');

            // A falha é do servidor, não de quem está tentando entrar.
            self::fail('O login está temporariamente indisponível. Tente de novo mais tarde.');
        }

        try {
            $result = Http::asForm()
                ->connectTimeout(5)
                ->timeout(10)
                ->post(self::VERIFY_URL, [
                    'secret' => config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ])
                ->json();
        } catch (ConnectionException) {
            $result = null;
        }

        if (is_array($result) && ($result['success'] ?? false) === true && ($result['action'] ?? null) === $action) {
            return;
        }

        Log::warning('turnstile: token recusado', [
            'action' => $action,
            'returned_action' => is_array($result) ? ($result['action'] ?? null) : null,
            'errors' => is_array($result) ? ($result['error-codes'] ?? []) : ['sem resposta da Cloudflare'],
            'ip' => $request->ip(),
        ]);

        if (! is_array($result)) {
            self::fail('Não conseguimos falar com a Cloudflare para confirmar o captcha. Tente de novo em instantes.');
        }

        self::refuse($request);
    }

    /** O token recusado: a tentativa fica registrada com o código do e-mail já validado, se a tela tem e-mail. */
    private static function refuse(Request $request): never
    {
        $email = $request instanceof FormRequest ? ($request->validated()['email'] ?? null) : null;
        app(RejectedAttempts::class)->record($request, 'captcha', is_string($email) ? $email : null);

        self::fail();
    }

    private static function fail(string $message = 'Não foi possível confirmar que você não é um robô. Tente de novo.'): never
    {
        throw ValidationException::withMessages([self::FIELD => $message]);
    }
}
