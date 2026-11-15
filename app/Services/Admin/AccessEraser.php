<?php

namespace App\Services\Admin;

use App\Models\AccessEvent;
use App\Models\DeviceSighting;
use App\Models\PageView;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Acessos: apaga um acesso, ou tudo de um IP (os acessos e os eventos,
 * no período inteiro que as tabelas guardam, não só no recorte da tela).
 * Devolve quantos saíram. Apagar um IP leva junto os códigos de aparelho
 * vistos nele (DeviceSightings).
 */
class AccessEraser
{
    public function delete(PageView $view): int
    {
        return (int) $view->delete();
    }

    /** @return array{0: int, 1: int} registros apagados (acessos e eventos) e códigos de aparelho */
    public function deleteIp(string $ip): array
    {
        return DB::transaction(fn () => [
            PageView::query()->where('ip', $ip)->delete() + AccessEvent::query()->where('ip', $ip)->delete(),
            DeviceSighting::query()->where('ip', $ip)->delete(),
        ]);
    }

    /**
     * Quantos acessos, eventos e códigos de aparelho distintos cada IP tem guardados (o período inteiro), para o
     * diálogo dizer quantos sairão. Uma consulta só, com os IPs da tela.
     *
     * @return array<string, array{views: int, events: int, devices: int}>
     */
    public function countByIp(iterable $ips): array
    {
        $ips = collect($ips)->filter()->unique()->values()->all();

        return $ips === [] ? [] : collect(DB::select(
            'select x.ip, (select count(*) from page_views p where p.ip = x.ip) as views,'
            .' (select count(*) from access_events e where e.ip = x.ip) as events,'
            .' (select count(distinct fingerprint) from device_sightings d where d.ip = x.ip) as devices'
            .' from unnest(cast(? as text[])) as x(ip)',
            ['{'.implode(',', array_map(fn (string $ip) => '"'.addcslashes($ip, '"\\').'"', $ips)).'}'],
        ))->mapWithKeys(fn (object $row) => [$row->ip => ['views' => (int) $row->views, 'events' => (int) $row->events, 'devices' => (int) $row->devices]])->all();
    }
}
