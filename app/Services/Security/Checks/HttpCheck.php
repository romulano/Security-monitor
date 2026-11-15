<?php

namespace App\Services\Security\Checks;

use App\Services\Security\CheckResult;
use App\Services\Security\SiteProbe;

/**
 * O que a página inicial e a porta 80 dizem: redirecionamento para HTTPS, HSTS, cabeçalhos de
 * segurança, versão no Server, cookies e conteúdo misto. Só nomes e flags: nenhum valor de cookie,
 * nenhum pedaço da página é guardado.
 */
class HttpCheck implements SiteCheck
{
    /** Cabeçalhos que dependem da página inicial em HTTPS. */
    private const HOME_IDS = ['http.hsts', 'hdr.csp', 'hdr.frame', 'hdr.nosniff', 'hdr.referrer', 'hdr.permissions', 'hdr.coop', 'leak.server', 'leak.powered', 'cookie.flags', 'mixed.content'];

    /** Cookies cujo nome indica sessão ou autenticação: esses precisam de HttpOnly e SameSite. */
    private const SESSION_COOKIE = '/sess|auth|token|sid|jwt|login|remember/i';

    /**
     * O cookie do token CSRF (XSRF-TOKEN do Laravel, csrftoken do Django) é legível por JavaScript de
     * propósito, para o front mandar o cabeçalho: exigir HttpOnly dele seria um falso positivo.
     */
    private const CSRF_COOKIE = '/xsrf|csrf/i';

    public function ids(): array
    {
        return ['http.redirect', ...self::HOME_IDS];
    }

    public function run(SiteProbe $probe): array
    {
        $home = $probe->home();

        if (! $probe->answered($home)) {
            $why = $home['blocked'] ? (string) $home['error'] : 'o site não respondeu em HTTPS';

            return [
                $this->redirect($probe),
                ...array_map(fn (string $id) => CheckResult::untested($id, $why), self::HOME_IDS),
            ];
        }

        // A página inicial não chegou em HTTPS: o certificado e o redirecionamento já dizem o problema.
        if (! str_starts_with((string) $home['url'], 'https://')) {
            return [
                $this->redirect($probe),
                ...array_map(fn (string $id) => CheckResult::untested($id, 'a página inicial não termina em HTTPS'), self::HOME_IDS),
            ];
        }

        return [
            $this->redirect($probe),
            $this->hsts($home),
            $this->csp($home),
            $this->frame($home),
            $this->nosniff($home),
            $this->referrer($home),
            $this->permissions($home),
            $this->coop($home),
            $this->serverVersion($home),
            $this->poweredBy($home),
            ...$this->cookies($home),
            $this->mixedContent($home),
        ];
    }

    private function redirect(SiteProbe $probe): CheckResult
    {
        $response = $probe->http();

        if ($response['blocked']) {
            return CheckResult::untested('http.redirect', (string) $response['error']);
        }

        if (! $probe->answered($response)) {
            return CheckResult::ok('http.redirect', 'porta 80 fechada');
        }

        $status = (int) $response['status'];
        $location = (string) SiteProbe::header($response, 'location');

        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            return str_starts_with(strtolower($location), 'https://')
                ? CheckResult::ok('http.redirect', "redireciona ({$status}) para HTTPS")
                : CheckResult::critical('http.redirect', "redireciona ({$status}) sem sair do HTTP");
        }

        return $status < 400
            ? CheckResult::critical('http.redirect', "responde {$status} em HTTP sem redirecionar")
            : CheckResult::warning('http.redirect', "a porta 80 responde {$status}");
    }

    private function hsts(array $home): CheckResult
    {
        $value = SiteProbe::header($home, 'strict-transport-security');

        if ($value === null) {
            return CheckResult::warning('http.hsts', 'ausente');
        }

        preg_match('/max-age\s*=\s*"?(\d+)/i', $value, $m);
        $maxAge = (int) ($m[1] ?? 0);

        if ($maxAge < 15552000) {
            return CheckResult::warning('http.hsts', "max-age curto ({$maxAge} s; o mínimo é 15552000)");
        }

        return CheckResult::ok('http.hsts', "max-age={$maxAge}".(stripos($value, 'includesubdomains') !== false ? ', includeSubDomains' : ''));
    }

    private function csp(array $home): CheckResult
    {
        $csp = SiteProbe::header($home, 'content-security-policy');

        if ($csp === null) {
            return SiteProbe::header($home, 'content-security-policy-report-only') !== null
                ? CheckResult::warning('hdr.csp', 'só em Report-Only: observa, não bloqueia')
                : CheckResult::warning('hdr.csp', 'ausente');
        }

        // Só a diretiva de script (ou o default-src, que a cobre) importa para o XSS.
        foreach (explode(';', $csp) as $directive) {
            $directive = trim($directive);

            if (preg_match('/^(script-src|default-src)\s/i', $directive) && preg_match("/'unsafe-inline'|'unsafe-eval'|(\s|^)\*(\s|$)/", $directive) && ! str_contains($directive, "'nonce-")) {
                return CheckResult::warning('hdr.csp', 'permite script inline, eval ou qualquer origem');
            }
        }

        return CheckResult::ok('hdr.csp', 'presente');
    }

    private function frame(array $home): CheckResult
    {
        $xfo = SiteProbe::header($home, 'x-frame-options');
        $csp = (string) SiteProbe::header($home, 'content-security-policy');

        return $xfo !== null || stripos($csp, 'frame-ancestors') !== false
            ? CheckResult::ok('hdr.frame', $xfo !== null ? "X-Frame-Options: {$xfo}" : 'frame-ancestors na CSP')
            : CheckResult::warning('hdr.frame', 'ausente');
    }

    private function nosniff(array $home): CheckResult
    {
        return stripos((string) SiteProbe::header($home, 'x-content-type-options'), 'nosniff') !== false
            ? CheckResult::ok('hdr.nosniff', 'nosniff')
            : CheckResult::warning('hdr.nosniff', 'ausente');
    }

    private function referrer(array $home): CheckResult
    {
        $value = SiteProbe::header($home, 'referrer-policy');

        return match (true) {
            $value === null => CheckResult::warning('hdr.referrer', 'ausente'),
            stripos($value, 'unsafe-url') !== false => CheckResult::warning('hdr.referrer', 'unsafe-url manda o endereço completo a todo site'),
            default => CheckResult::ok('hdr.referrer', $value),
        };
    }

    private function permissions(array $home): CheckResult
    {
        return SiteProbe::header($home, 'permissions-policy') !== null || SiteProbe::header($home, 'feature-policy') !== null
            ? CheckResult::ok('hdr.permissions', 'presente')
            : CheckResult::warning('hdr.permissions', 'ausente');
    }

    private function coop(array $home): CheckResult
    {
        $value = SiteProbe::header($home, 'cross-origin-opener-policy');

        return $value !== null ? CheckResult::ok('hdr.coop', $value) : CheckResult::warning('hdr.coop', 'ausente');
    }

    private function serverVersion(array $home): CheckResult
    {
        $server = SiteProbe::header($home, 'server');

        return $server !== null && preg_match('/\d+\.\d+/', $server)
            ? CheckResult::warning('leak.server', "Server: {$server}")
            : CheckResult::ok('leak.server', $server !== null ? "Server: {$server}" : 'sem cabeçalho Server');
    }

    private function poweredBy(array $home): CheckResult
    {
        $value = SiteProbe::header($home, 'x-powered-by');

        return $value !== null
            ? CheckResult::warning('leak.powered', "X-Powered-By: {$value}")
            : CheckResult::ok('leak.powered', 'ausente');
    }

    /** @return list<CheckResult> um por cookie da página inicial (ou um "nenhum cookie"). */
    private function cookies(array $home): array
    {
        $raw = $home['headers']['set-cookie'] ?? [];

        if ($raw === []) {
            return [CheckResult::ok('cookie.flags', 'nenhum cookie na página inicial')];
        }

        $results = [];

        foreach ($raw as $cookie) {
            $parts = array_map('trim', explode(';', (string) $cookie));
            $name = trim((string) strtok((string) array_shift($parts), '='));

            if ($name === '') {
                continue;
            }

            $flags = array_map('strtolower', $parts);
            $has = fn (string $flag) => in_array($flag, $flags, true);
            $sameSite = (bool) array_filter($flags, fn ($f) => str_starts_with($f, 'samesite='));
            $session = preg_match(self::SESSION_COOKIE, $name) && ! preg_match(self::CSRF_COOKIE, $name);

            $missing = [];

            if (! $has('secure')) {
                $missing[] = 'Secure';
            }

            if ($session && ! $has('httponly')) {
                $missing[] = 'HttpOnly';
            }

            if ($session && ! $sameSite) {
                $missing[] = 'SameSite';
            }

            $results[] = $missing === []
                ? CheckResult::ok('cookie.flags', 'Secure'.($has('httponly') ? ', HttpOnly' : '').($sameSite ? ', SameSite' : ''), $name)
                : CheckResult::warning('cookie.flags', 'falta '.implode(', ', $missing), $name);
        }

        return $results ?: [CheckResult::ok('cookie.flags', 'nenhum cookie na página inicial')];
    }

    private function mixedContent(array $home): CheckResult
    {
        $count = preg_match_all('#<(?:script|img|iframe|link|source|video|audio)\b[^>]*\b(?:src|href)\s*=\s*["\']http://#i', (string) $home['body']);

        return $count
            ? CheckResult::warning('mixed.content', "{$count} recurso(s) carregados por http://")
            : CheckResult::ok('mixed.content', 'nenhum recurso por http://');
    }
}
