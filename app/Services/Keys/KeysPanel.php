<?php

namespace App\Services\Keys;

use App\Models\ExposedKey;
use App\Models\KeySite;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Services\Domains\DomainOwnership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A área "Chaves expostas": os domínios comprovados que a conta vê (só eles podem ser varridos), a
 * varredura de cada um e o resumo das chaves; e o card da Integração.
 */
final class KeysPanel
{
    public function __construct(private DomainOwnership $ownership, private KeysPage $keys) {}

    /**
     * @return array{rows: list<array<string, mixed>>, manageable: array<int, bool>}
     */
    public function page(User $user): array
    {
        $proven = $this->ownership->proven($user);
        $manageable = OwnedDomain::query()->manageableBy($user)->whereIn('id', $proven->pluck('id'))->pluck('id')->flip();
        $sites = KeySite::query()->whereIn('owned_domain_id', $proven->pluck('id'))->get()->keyBy('owned_domain_id');
        $counts = $this->counts($proven->pluck('id'));

        $rows = $proven->map(fn (OwnedDomain $owned) => [
            'owned' => $owned,
            'site' => $sites[$owned->id] ?? null,
            'counts' => $counts[$owned->id] ?? ['total' => 0, 'open' => 0, 'leak' => 0, 'allowed' => 0],
            'state' => self::state($owned, $sites[$owned->id] ?? null),
            'manageable' => $manageable->has($owned->id),
            'provable' => KeySites::provable($owned),
        ])->values()->all();

        return ['rows' => $rows];
    }

    /**
     * A página das chaves de um domínio: a varredura dele, o estado, se a conta configura e a lista (KeysPage).
     *
     * @return array<string, mixed>
     */
    public function domain(User $user, OwnedDomain $owned): array
    {
        $site = KeySite::query()->where('owned_domain_id', $owned->id)->first();

        return ['owned' => $owned, 'site' => $site, 'state' => self::state($owned, $site),
            'manageable' => OwnedDomain::query()->manageableBy($user)->whereKey($owned->id)->exists()] + $this->keys->page($owned);
    }

    /** @return array{proven: int, active: int, open: int, leak: int, last: ?Carbon} */
    public function card(User $user): array
    {
        $ids = $this->ownership->proven($user)->pluck('id');
        $sites = KeySite::query()->whereIn('owned_domain_id', $ids)->get();
        $counts = collect($this->counts($ids));

        return ['proven' => $ids->count(), 'active' => $sites->where('status', 'active')->count(), 'open' => $counts->sum('open'),
            'leak' => $counts->sum('leak'), 'last' => $sites->max('last_scan_at')];
    }

    /** [o que a tela diz, o tom da pílula] */
    public static function state(OwnedDomain $owned, ?KeySite $site): array
    {
        return match (true) {
            ! KeySites::provable($owned) => ['TXT sumiu', 'erro'],
            $site === null => ['não ativada', 'neutro'],
            $site->scan_status === 'running' => ['varrendo agora', 'ativo'],
            $site->scan_status === 'queued' => ['na fila', 'ativo'],
            ! $site->isActive() => ['pausada', 'neutro'],
            $site->last_result === 'failed' => ['última falhou', 'alerta'],
            default => ['ativa', 'ok'],
        };
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return array<int, array{total: int, open: int, leak: int, allowed: int}>
     */
    private function counts(Collection $ids): array
    {
        $out = [];

        foreach (ExposedKey::query()->whereIn('owned_domain_id', $ids)->toBase()
            ->selectRaw("owned_domain_id as d, coalesce(verdict, 'open') as v, count(*) as n")->groupBy('d', 'v')->get() as $row) {
            $out[$row->d] ??= ['total' => 0, 'open' => 0, 'leak' => 0, 'allowed' => 0];
            $out[$row->d][$row->v] = (int) $row->n;
            $out[$row->d]['total'] += (int) $row->n;
        }

        return $out;
    }
}
