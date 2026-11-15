<?php

namespace App\Services\Monitoring;

use App\Models\PageView;
use App\Support\Settings;
use App\Support\UserAgents;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin → Acessos: grava cada página aberta no site, depois que a resposta já
 * saiu (o terminate do RecordPageView), e o tempo que ela ficou aberta, que o navegador manda ao sair
 * (resources/js/app.js). Liga e desliga no próprio Admin → Acessos.
 *
 * O IP vem do X-Forwarded-For que a borda monta (trustProxies) e o país, do
 * CF-IPCountry que a Cloudflare põe em toda requisição. Fica de fora o admin
 * (as páginas e quem é admin), o que não é página (JSON, arquivos, POST, o
 * sitemap) e a pré-carga do navegador. Robô declarado entra, marcado, com o
 * dono e o tipo que ele declara.
 */
class PageViewRecorder
{
    public const SETTING = 'monitoring.enabled';

    /** Até onde vale o tempo que o navegador manda: a sessão do site dura 4 h. */
    public const MAX_DURATION_MS = 4 * 3600 * 1000;

    public function enabled(): bool
    {
        return Settings::bool(self::SETTING);
    }

    public function toggle(): bool
    {
        Settings::set(self::SETTING, $on = ! $this->enabled());

        return $on;
    }

    /** O token que a página leva para mandar o tempo depois (a monitoria ligada é conferida no RecordPageView). */
    public function token(Request $request): ?string
    {
        // /up é o healthcheck do Docker e do deploy, a cada poucos segundos. O sitemap é lido por robôs, não é
        // página de ninguém (como o robots.txt, que o nginx entrega antes do PHP), e nem é HTML.
        return $request->isMethod('GET') && ! $request->is('admin', 'admin/*', 'up', 'sitemap.xml', 'sitemap-*.xml')
            ? (string) Str::uuid()
            : null;
    }

    /** O acesso gravado, para o evento da mesma requisição apontar para ele (AccessEventRecorder); nulo se não conta. */
    public function record(string $token, Request $request, Response $response): ?PageView
    {
        if (! $this->countable($request, $response)) {
            return null;
        }

        $country = strtoupper((string) $request->header('CF-IPCountry'));
        // De quem é o robô e para que lê o site, pelo que ele declara (RobotCatalog); nulo: gente.
        $robot = UserAgents::robot($request->userAgent());

        return PageView::query()->create([
            'token' => $token,
            'user_id' => $request->user()?->id,
            'ip' => (string) $request->ip(),
            'country' => preg_match('/^[A-Z][A-Z0-9]$/', $country) ? $country : null,
            'user_agent' => self::clip($request->userAgent()),
            'is_bot' => $robot !== null,
            'bot_owner' => $robot['owner'] ?? null,
            'bot_kind' => $robot['kind'] ?? null,
            // Sem o link assinado nem o code do OAuth, que valem como senha (RecordedUrl).
            'path' => self::clip(RecordedUrl::path($request)) ?? '/',
            'referer' => self::clip(RecordedUrl::referer($request->headers->get('referer'), $request)),
            'status' => $response->getStatusCode(),
            'created_at' => now(),
        ]);
    }

    /** O tempo da página só cresce (o navegador manda de novo a cada vez que a aba some) e só nas primeiras 4 h. */
    public function duration(string $token, int $milliseconds): void
    {
        PageView::query()
            ->where('token', $token)
            ->where('created_at', '>=', now()->subMilliseconds(self::MAX_DURATION_MS))
            ->where(fn ($q) => $q->whereNull('duration_ms')->orWhere('duration_ms', '<', $milliseconds))
            ->update(['duration_ms' => min($milliseconds, self::MAX_DURATION_MS)]);
    }

    private function countable(Request $request, Response $response): bool
    {
        $purpose = strtolower($request->header('Sec-Purpose', $request->header('Purpose', '')));

        return str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            && ! $request->expectsJson()
            && ! $request->ajax()
            && ! str_contains($purpose, 'prefetch')
            && ! $request->user()?->is_admin;
    }

    /**
     * O byte que não é UTF-8 vira `?` (antes, o `sqlmap\xff` inteiro virava nulo), e os
     * caracteres de controle, os invisíveis e os que invertem o texto (U+202E) viram espaço.
     */
    public static function clip(?string $value): ?string
    {
        $value = mb_scrub((string) $value, 'UTF-8');
        $value = trim(preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? $value);

        return $value === '' ? null : mb_substr($value, 0, 500);
    }
}
