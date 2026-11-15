<?php

namespace App\Services\Monitoring;

use App\Models\DeviceSighting;
use App\Models\PageView;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * O código do aparelho de quem navega sem login, só para segurança: reconhecer
 * o atacante que troca de IP (/privacidade, seção 2). O navegador manda um hash
 * dos sinais do aparelho no beacon do tempo da página; aqui fica só o HMAC dele
 * (APP_KEY), com o IP, o país, o navegador e o dia do PageView.
 *
 * Sem vínculo com conta, de propósito: só grava para PageView sem user_id, e a
 * leitura (ipsOf, rarity) só lê device_sightings, sem join com users nem page_views.
 */
class DeviceSightings
{
    /** A página só manda o código nas primeiras 4 h (a sessão do site), como o tempo. */
    private const TOKEN_HOURS = 4;

    public function record(string $token, string $hash): void
    {
        $view = PageView::query()
            ->where('token', $token)
            ->where('created_at', '>=', now()->subHours(self::TOKEN_HOURS))
            ->whereNull('user_id')
            ->first(['ip', 'country', 'user_agent']);

        // Sem a página, vencida ou de quem entrou na conta no meio: nada.
        if (! $view) {
            return;
        }

        $fingerprint = hash_hmac('sha256', $hash, 'secmon:aparelho:'.config('app.key'));
        $day = now()->utc()->toDateString();
        $known = DeviceSighting::query()->where(['fingerprint' => $fingerprint, 'ip' => $view->ip, 'day' => $day])->exists();

        if (! $known && DeviceSighting::query()->where(['ip' => $view->ip, 'day' => $day])->count() >= (int) config('secmon.monitoring.devices.per_ip_per_day')) {
            return;
        }

        $now = now();
        DB::table('device_sightings')->upsert(
            [[
                'fingerprint' => $fingerprint, 'ip' => $view->ip, 'day' => $day, 'first_seen' => $now, 'last_seen' => $now,
                'hits' => 1, 'country' => $view->country, 'user_agent' => $view->user_agent,
            ]],
            ['fingerprint', 'ip', 'day'],
            ['last_seen' => $now, 'hits' => DB::raw('device_sightings.hits + 1'), 'country', 'user_agent'],
        );
    }

    /**
     * Os IPs em que o código apareceu na janela (30 dias), do mais recente ao mais antigo:
     * país e navegador da última vez, primeira e última vez.
     *
     * @return Collection<int, object{ip: string, country: ?string, user_agent: ?string, first_seen: string, last_seen: string}>
     */
    public function ipsOf(string $fingerprint, int $limit = 20): Collection
    {
        return DB::table('device_sightings')
            ->where('fingerprint', $fingerprint)
            ->where('last_seen', '>=', $this->windowStart())
            ->select('ip')
            ->selectRaw('min(first_seen) as first_seen, max(last_seen) as last_seen')
            ->selectRaw('(array_agg(country order by last_seen desc))[1] as country')
            ->selectRaw('(array_agg(user_agent order by last_seen desc))[1] as user_agent')
            ->groupBy('ip')
            ->orderByDesc('last_seen')
            ->limit($limit)
            ->get();
    }

    /**
     * Quão raro é o código (IPs distintos na janela): `rare` liga os IPs com confiança,
     * `medium` liga com o número ao lado, `common` não liga (aparelho comum).
     *
     * @return array{ips: int, level: 'rare'|'medium'|'common'}
     */
    public function rarity(string $fingerprint): array
    {
        $ips = (int) DB::table('device_sightings')
            ->where('fingerprint', $fingerprint)
            ->where('last_seen', '>=', $this->windowStart())
            ->distinct()
            ->count('ip');

        $level = match (true) {
            $ips <= (int) config('secmon.monitoring.devices.rare_ips') => 'rare',
            $ips <= (int) config('secmon.monitoring.devices.common_ips') => 'medium',
            default => 'common',
        };

        return ['ips' => $ips, 'level' => $level];
    }

    private function windowStart(): Carbon
    {
        return now()->subDays((int) config('secmon.monitoring.devices.window_days'));
    }
}
