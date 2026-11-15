<?php

namespace App\Services\Keys;

use App\Models\ExposedKey;
use App\Models\OwnedDomain;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * O registro das chaves de um domínio comprovado: o que a varredura de chaves e o mapeamento dos
 * testes acham (já conferido: o MappingFacts no lado dos testes, o KeyDetector no da varredura)
 * vira uma linha por chave em `exposed_keys`, reconhecida pela impressão digital de uma vez para
 * outra.
 *
 * A decisão do dono (`verdict`) só muda pelo `classify`: a chave que volta a aparecer guarda o que
 * ele decidiu. Os lugares são os da vez mais recente que viu a chave.
 */
final class KeyLedger
{
    /**
     * Os lugares guardados por chave: da varredura, a URL, a linha e a coluna de cada ocorrência (`spots`); do
     * mapeamento dos testes, o nome e o 1º caminho com quantas vezes.
     */
    public const MAX_LOCATIONS = 20;

    /**
     * Grava as chaves achadas, nome a nome.
     *
     * @param  array<string, list<array{kind: string, masked: string, name: ?string, path: string, fp: string, count: int, spots?: list<array{url: string, path: string, line: int, col: int}>}>>  $byHost
     * @return array{keys: int, new: int} quantas chaves diferentes foram vistas, e quantas são novas no domínio
     */
    public function record(OwnedDomain $owned, array $byHost, string $source, ?Carbon $now = null): array
    {
        $now ??= now();
        $seen = [];

        foreach ($byHost as $host => $keys) {
            foreach ($keys as $key) {
                $one = $seen[$key['fp']] ??= ['key' => $key, 'locations' => [], 'occurrences' => 0];
                $one['occurrences'] += $key['count'];

                $places = isset($key['spots'])
                    ? array_map(fn (array $spot) => ['host' => (string) $host, 'path' => $spot['path'], 'url' => $spot['url'], 'line' => $spot['line'], 'col' => $spot['col']], $key['spots'])
                    : [['host' => (string) $host, 'path' => $key['path'], 'count' => $key['count']]];

                foreach ($places as $place) {
                    if (count($one['locations']) < self::MAX_LOCATIONS) {
                        $one['locations'][] = $place;
                    }
                }

                $seen[$key['fp']] = $one;
            }
        }

        if ($seen === []) {
            return ['keys' => 0, 'new' => 0];
        }

        $existing = ExposedKey::query()->where('owned_domain_id', $owned->id)->whereIn('fingerprint', array_keys($seen))
            ->lockForUpdate()->get()->keyBy('fingerprint');
        $new = 0;

        foreach ($seen as $fingerprint => $one) {
            $key = $one['key'];
            $row = $existing[$fingerprint] ?? null;
            $values = ['kind' => $key['kind'], 'masked' => $key['masked'], 'name' => $key['name'], 'locations' => $one['locations'],
                'occurrences' => min($one['occurrences'], 1000000), 'source' => $source, 'last_seen_at' => $now];

            if ($row === null) {
                $new++;
                ExposedKey::query()->create($values + ['owned_domain_id' => $owned->id, 'fingerprint' => $fingerprint, 'first_seen_at' => $now]);
            } else {
                $row->forceFill($values)->save();
            }
        }

        return ['keys' => count($seen), 'new' => $new];
    }

    /** O que o dono decidiu sobre a chave: `allowed` (pode ficar exposta), `leak` (não deveria estar exposta) ou nulo (desfazer). */
    public function classify(ExposedKey $key, User $user, ?string $verdict, ?Carbon $now = null): void
    {
        $now ??= now();
        $key->forceFill(['verdict' => $verdict, 'verdict_by' => $verdict === null ? null : $user->id, 'verdict_at' => $verdict === null ? null : $now])->save();
    }
}
