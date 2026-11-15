<?php

namespace App\Services\Lookalike;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Estado de uma varredura completa que roda em vários jobs: a trava (uma por
 * Radar, para o agendador não começar outra enquanto os lotes andam) e o que
 * cada job deixa para o último, que junta tudo. Fica no cache e expira sozinho:
 * se um worker morrer no meio, a trava solta depois de `scan_lock_hours`.
 */
final class ScanRun
{
    /** Começa uma varredura do Radar; nulo se já há uma em andamento. */
    public static function begin(int $watchId): ?string
    {
        $id = (string) Str::uuid();

        return Cache::add(self::lockKey($watchId), $id, self::ttl()) ? $id : null;
    }

    public static function running(int $watchId): bool
    {
        return Cache::has(self::lockKey($watchId));
    }

    /** Solta a trava (só se ainda for desta varredura) e apaga o que ela guardou. */
    public static function end(int $watchId, string $runId, int $chunks = 0): void
    {
        if (Cache::get(self::lockKey($watchId)) === $runId) {
            Cache::forget(self::lockKey($watchId));
        }

        foreach (['meta', 'certs', ...array_map(fn (int $i) => "chunk.{$i}", range(0, max(0, $chunks - 1)))] as $key) {
            Cache::forget(self::dataKey($runId, $key));
        }
    }

    /** Quantas peças da varredura (lotes de DNS e certificados) ainda não deixaram resultado, nem o de falha. */
    public static function missing(string $runId): int
    {
        if (! $meta = self::get($runId, 'meta')) {
            return 0;
        }

        $missing = self::get($runId, 'certs') === null ? 1 : 0;

        for ($index = 0; $index < $meta['chunks']; $index++) {
            $missing += self::get($runId, "chunk.{$index}") === null ? 1 : 0;
        }

        return $missing;
    }

    public static function put(string $runId, string $key, mixed $value): void
    {
        Cache::put(self::dataKey($runId, $key), $value, self::ttl()->addHours(3));
    }

    public static function get(string $runId, string $key): mixed
    {
        return Cache::get(self::dataKey($runId, $key));
    }

    private static function lockKey(int $watchId): string
    {
        return "lookalikes:scan:{$watchId}";
    }

    private static function dataKey(string $runId, string $key): string
    {
        return "lookalikes:run:{$runId}:{$key}";
    }

    private static function ttl(): Carbon
    {
        return now()->addHours(max(1, (int) config('secmon.lookalikes.scan_lock_hours', 3)));
    }
}
