<?php

namespace App\Services\Admin;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Acessos: o mesmo aparelho (o código de DeviceSightings) em outros
 * IPs, só para segurança. Lê só device_sightings: nunca liga a users nem a
 * page_views (a /privacidade promete que o código não é ligado à conta).
 *
 * Confiança, pelos IPs distintos do código na janela (30 dias): raro (até
 * `rare_ips`) com o mesmo navegador é "alto"; raro com outro navegador, ou
 * até `common_ips`, é "provável"; acima disso, "aparelho comum", sem lista.
 */
class DeviceLinks
{
    /** Outros IPs listados por código, no máximo (acima de `common_ips` o código nem lista). */
    public const LIST = 20;

    /**
     * Os códigos vistos neste IP, cada um com os outros IPs em que apareceu. Duas consultas.
     *
     * @return Collection<int, object{fingerprint: string, ips: int, level: string, others: Collection<int, object>}>
     */
    public function forIp(string $ip): Collection
    {
        $since = $this->since();
        $codes = DB::table('device_sightings as d')->where('d.ip', $ip)->where('d.last_seen', '>=', $since)
            ->selectRaw('d.fingerprint, (array_agg(d.user_agent order by d.last_seen desc))[1] as user_agent,'
                .' (select count(distinct o.ip) from device_sightings o where o.fingerprint = d.fingerprint and o.last_seen >= ?) as ips', [$since])
            ->groupBy('d.fingerprint')->orderByDesc('ips')->get()
            ->each(fn (object $code) => $code->level = $this->level((int) $code->ips));

        $listed = $codes->where('level', '!=', 'common')->pluck('fingerprint')->all();
        $others = $listed === [] ? collect() : DB::query()->fromSub(
            DB::table('device_sightings')->whereIn('fingerprint', $listed)->where('ip', '<>', $ip)->where('last_seen', '>=', $since)
                ->selectRaw('fingerprint, ip, min(first_seen) as first_seen, max(last_seen) as last_seen,'
                    .' (array_agg(country order by last_seen desc))[1] as country, (array_agg(user_agent order by last_seen desc))[1] as user_agent,'
                    .' row_number() over (partition by fingerprint order by max(last_seen) desc) as position')
                ->groupBy('fingerprint', 'ip'),
            'o',
        )->where('position', '<=', self::LIST)->orderByDesc('last_seen')->get()->groupBy('fingerprint');

        return $codes->each(function (object $code) use ($others) {
            $code->others = ($others[$code->fingerprint] ?? collect())->each(
                fn (object $other) => $other->confidence = $code->level === 'rare' && $other->user_agent === $code->user_agent ? 'alto' : 'provável'
            );
        });
    }

    /**
     * Para os IPs da aba Ataques (sem os nossos): quantos outros IPs cada um divide com um código não comum, e os
     * códigos raros vistos em 2 ou mais desses IPs (aparelhos que trocaram de IP). Uma consulta.
     *
     * @param  list<string>  $ips
     * @return array{deviceIps: array<string, object{others: int, confidence: string}>, moved: Collection<int, object>}
     */
    public function forAttackers(array $ips): array
    {
        if ($ips === []) {
            return ['deviceIps' => [], 'moved' => collect()];
        }

        $set = '{'.implode(',', array_map(fn (string $ip) => '"'.addcslashes($ip, '"\\').'"', $ips)).'}';
        $since = $this->since();
        $codes = DB::table('device_sightings as d')->where('d.last_seen', '>=', $since)
            ->whereIn('d.fingerprint', fn ($q) => $q->select('fingerprint')->from('device_sightings')
                ->whereRaw('ip = any(cast(? as text[]))', [$set])->where('last_seen', '>=', $since))
            ->selectRaw('d.fingerprint, count(distinct d.ip) as ips, count(distinct d.user_agent) as uas,'
                .' array_to_json(array_agg(distinct d.ip) filter (where d.ip = any(cast(? as text[])))) as among', [$set])
            ->groupBy('d.fingerprint')->get()
            ->each(function (object $code) {
                $code->level = $this->level((int) $code->ips);
                $code->among = json_decode((string) $code->among, true) ?: [];
                $code->confidence = $code->level === 'rare' && (int) $code->uas === 1 ? 'alto' : 'provável';
            });

        $byIp = [];

        foreach ($codes->where('level', '!=', 'common') as $code) {
            foreach ($code->among as $ip) {
                $others = (int) $code->ips - 1;

                if ($others > 0 && $others > ($byIp[$ip]->others ?? 0)) {
                    $byIp[$ip] = (object) ['others' => $others, 'confidence' => $code->confidence];
                }
            }
        }

        return [
            'deviceIps' => $byIp,
            'moved' => $codes->where('level', 'rare')->filter(fn (object $code) => count($code->among) >= 2)->sortByDesc(fn (object $code) => count($code->among))->values(),
        ];
    }

    private function level(int $ips): string
    {
        return match (true) {
            $ips <= (int) config('secmon.monitoring.devices.rare_ips', 5) => 'rare',
            $ips <= (int) config('secmon.monitoring.devices.common_ips', 20) => 'medium',
            default => 'common',
        };
    }

    private function since(): string
    {
        return (string) now()->subDays((int) config('secmon.monitoring.devices.window_days', 30));
    }
}
