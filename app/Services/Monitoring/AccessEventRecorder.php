<?php

namespace App\Services\Monitoring;

use App\Models\AccessEvent;
use App\Models\PageView;
use App\Support\AttackSignatures;
use App\Support\UserAgents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin → Acessos: o que o site recusa ou que tem cara de ataque, gravado no
 * terminate do RecordPageView (a resposta já saiu). Vale para qualquer método e
 * qualquer resposta, inclusive JSON, ajax e pré-carga: o cabeçalho que o
 * cliente escolhe não tira ninguém do registro.
 *
 * Entra: resposta 4xx/5xx; método fora dos de formulário e API (OPTIONS,
 * PROPFIND, inventado); /admin para quem não é admin; assinatura de ataque
 * (AttackSignatures), mesmo com 200; e 419/429 no login e no cadastro. Fica de
 * fora o admin, o healthcheck e o tempo de página. A tentativa recusada de login
 * e cadastro (RejectedAttempts) entra sempre, mesmo com a monitoria desligada.
 *
 * Contra inundação, um teto por IP e outro geral por minuto
 * (secmon.access_events): passado algum deles, só soma na contagem do minuto
 * (access_event_overflow). Duas consultas por requisição, no máximo.
 */
class AccessEventRecorder
{
    /** A tentativa recusada que o RejectedAttempts marcou na requisição: [auth, account_hmac]. */
    public const ATTEMPT = 'access_event_attempt';

    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** O login e o cadastro: o 419 (formulário velho) e o 429 (excesso) ali são tentativa de entrar. */
    private const AUTH_PATHS = ['login', 'cadastro', 'cadastro/github', 'admin/login'];

    public function record(Request $request, Response $response, ?PageView $view = null, bool $monitoring = true): void
    {
        $attempt = $request->attributes->get(self::ATTEMPT);

        if (! $monitoring && $attempt === null) {
            return;
        }

        $status = $response->getStatusCode();
        $method = $request->getRealMethod();
        // Primeiro o que se decide em memória; o banco só para o que interessa.
        $category = $attempt !== null ? 'autenticacao' : $this->category($request, $method, $status);

        if (($category === null && $status < 400) || $request->is('up') || $request->routeIs('page-views.duration')) {
            return;
        }

        if ($request->user()?->is_admin) {
            return;
        }

        $now = now();
        $since = $now->copy()->subMinute();
        $counts = DB::table('access_events')->where('created_at', '>=', $since)
            ->selectRaw('count(*) filter (where ip = ?) as ip, count(*) as total', [$ip = (string) $request->ip()])
            ->first();

        if ((int) $counts->ip >= (int) config('secmon.access_events.per_ip_per_minute', 20)
            || (int) $counts->total >= (int) config('secmon.access_events.per_minute', 120)) {
            DB::statement(
                'insert into access_event_overflow (minute, dropped) values (?, 1)'
                .' on conflict (minute) do update set dropped = access_event_overflow.dropped + 1',
                [$now->copy()->startOfMinute()],
            );

            return;
        }

        $country = strtoupper((string) $request->header('CF-IPCountry'));
        $route = $request->route();

        AccessEvent::query()->create([
            'created_at' => $now,
            'ip' => $ip,
            'country' => preg_match('/^[A-Z][A-Z0-9]$/', $country) ? $country : null,
            'user_id' => $request->user()?->id,
            'method' => substr($method, 0, 16),
            'path' => PageViewRecorder::clip(RecordedUrl::path($request)) ?? '/',
            'route' => $route ? substr((string) ($route->getName() ?? $route->uri()), 0, 80) : null,
            'status' => $status,
            'category' => $category,
            'auth' => $attempt['auth'] ?? null,
            'account_hmac' => $attempt['account_hmac'] ?? null,
            'user_agent' => PageViewRecorder::clip($request->userAgent()),
            'bot_kind' => UserAgents::robot($request->userAgent())['kind'] ?? null,
            'referer' => PageViewRecorder::clip(RecordedUrl::referer($request->headers->get('referer'), $request)),
            'page_view_id' => $view?->id,
        ]);
    }

    /** A categoria do evento (AttackSignatures::CATEGORIES); nulo: só a resposta recusada, sem cara de nada. */
    private function category(Request $request, string $method, int $status): ?string
    {
        if (! in_array($method, self::METHODS, true)) {
            return 'metodo';
        }

        $attack = AttackSignatures::classify(
            $request->getRequestUri(),
            $request->userAgent(),
            $request->headers->get('referer'),
            array_keys($request->headers->all()),
        );

        return $attack
            ?? (in_array($status, [419, 429], true) && $request->is(...self::AUTH_PATHS) ? 'autenticacao' : null)
            ?? ($request->is('admin', 'admin/*') ? 'area_admin' : null);
    }
}
