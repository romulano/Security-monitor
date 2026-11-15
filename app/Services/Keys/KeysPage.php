<?php

namespace App\Services\Keys;

use App\Models\ExposedKey;
use App\Models\KeySite;
use App\Models\OwnedDomain;
use App\Services\Pentest\Keys\KeyKinds;

/**
 * A lista das chaves de um domínio comprovado: as que o dono ainda não decidiu primeiro (as que não
 * deveriam estar no site antes das públicas), depois as decididas; cada uma com o que o Security Monitor
 * sugere, onde ela apareceu e se a última varredura completa ainda a viu.
 */
final class KeysPage
{
    /** decisão => [o que a tela diz, o tom] */
    public const VERDICTS = [
        'allowed' => ['pode ficar exposta', 'ok'],
        'leak' => ['não deveria estar exposta', 'erro'],
    ];

    /** o que se espera do tipo => [a sugestão, o tom] */
    public const SUGGESTIONS = [
        'secreta' => ['não deveria estar no site', 'erro'],
        'depende' => ['confira', 'alerta'],
        'publica' => ['feita para ser pública', 'neutro'],
    ];

    private const ORDER = ['secreta' => 0, 'depende' => 1, 'publica' => 2];

    /**
     * @return array{keys: list<array<string, mixed>>, counts: array{total: int, open: int, leak: int, allowed: int}}
     */
    public function page(?OwnedDomain $owned): array
    {
        if ($owned === null) {
            return ['keys' => [], 'counts' => self::counts(null)];
        }

        // A última varredura que terminou inteira: a chave que ela não viu pode ter saído do site.
        $site = KeySite::query()->where('owned_domain_id', $owned->id)->first();
        $swept = $site?->last_result === 'ok' ? $site->last_scan_at : null;

        $keys = ExposedKey::query()->where('owned_domain_id', $owned->id)->get()
            ->sortBy(fn (ExposedKey $key) => [$key->verdict !== null, self::ORDER[KeyKinds::exposure($key->kind)], -$key->last_seen_at->getTimestamp(), $key->id])
            ->values()
            ->map(fn (ExposedKey $key) => [
                'key' => $key,
                'label' => KeyKinds::label($key->kind),
                'hint' => KeyKinds::hint($key->kind),
                'suggestion' => self::SUGGESTIONS[KeyKinds::exposure($key->kind)],
                'verdict' => $key->verdict === null ? null : self::VERDICTS[$key->verdict],
                'gone' => $swept !== null && $key->last_seen_at->lt($swept),
            ])
            ->all();

        return ['keys' => $keys, 'counts' => self::counts($owned)];
    }

    /** @return array{total: int, open: int, leak: int, allowed: int} */
    public static function counts(?OwnedDomain $owned): array
    {
        $by = $owned === null ? collect() : ExposedKey::query()->where('owned_domain_id', $owned->id)->toBase()
            ->selectRaw("coalesce(verdict, 'open') as v, count(*) as n")->groupBy('v')->pluck('n', 'v');
        $counts = ['open' => (int) ($by['open'] ?? 0), 'leak' => (int) ($by['leak'] ?? 0), 'allowed' => (int) ($by['allowed'] ?? 0)];

        return ['total' => array_sum($counts)] + $counts;
    }
}
