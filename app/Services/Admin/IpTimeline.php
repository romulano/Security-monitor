<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;

/**
 * Admin → Acessos → Por IP: a ficha de um IP no período (até os 180 dias que
 * as tabelas guardam). O resumo dos acessos e dos eventos e uma linha do tempo
 * só, com as páginas (page_views, com a categoria do evento da mesma
 * requisição) e os eventos que não são página. O 404 que está nas duas tabelas
 * aparece uma vez. Quatro consultas, pelo índice (ip, created_at).
 */
class IpTimeline
{
    public const PERIODS = AccessReport::PERIODS + ['180d' => 4320];

    /**
     * @param  array{ip: string, periodo?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function show(array $filters): array
    {
        $filters = ['ip' => $filters['ip'], 'periodo' => $filters['periodo'] ?? '180d'];
        $since = now()->subHours(self::PERIODS[$filters['periodo']] ?? 4320);
        $ip = $filters['ip'];

        return DB::transaction(function () use ($filters, $since, $ip): array {
            DB::statement("set local statement_timeout = '5s'");

            $views = DB::table('page_views as p')->leftJoin('access_events as e', 'e.page_view_id', '=', 'p.id')
                ->where('p.ip', $ip)->where('p.created_at', '>=', $since)
                ->selectRaw("'acesso' as source, p.id, p.created_at, 'GET' as method, p.path, p.status, e.category, null as auth,"
                    .' p.user_agent, p.referer, p.duration_ms, p.bot_kind');
            $events = DB::table('access_events')->where('ip', $ip)->where('created_at', '>=', $since)->whereNull('page_view_id')
                ->selectRaw("'evento' as source, id, created_at, method, path, status, category, auth, user_agent, referer, null as duration_ms, bot_kind");

            return [
                'filters' => $filters,
                'views' => DB::table('page_views')->where('ip', $ip)->where('created_at', '>=', $since)->selectRaw(
                    'count(*) as total, count(duration_ms) as timed, bool_or(is_bot) as bot, min(created_at) as first_at, max(created_at) as last_at,'
                    ." string_agg(distinct coalesce(country, '—'), ', ') as countries, array_to_json((array_agg(distinct user_agent))[1:10]) as user_agents,"
                    .' count(distinct user_id) as accounts'
                )->first(),
                'events' => DB::table('access_events')->where('ip', $ip)->where('created_at', '>=', $since)->selectRaw(
                    'count(*) as total, count(*) filter (where auth is not null) as refusals, count(distinct account_hmac) as accounts,'
                    ." min(created_at) as first_at, max(created_at) as last_at, string_agg(distinct category, ', ') as categories"
                )->first(),
                'timeline' => DB::query()->fromSub($views->unionAll($events), 't')
                    ->orderByDesc('created_at')->orderByDesc('id')->paginate(50)->withQueryString(),
            ];
        });
    }
}
