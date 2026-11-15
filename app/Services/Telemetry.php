<?php

namespace App\Services;

use App\Models\CrawlRun;
use App\Models\NewsCase;
use App\Models\Source;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Faixa de telemetria da página "Sobre", a única parte viva dela: lida do
 * banco, com cache de um minuto (três contagens simples).
 */
class Telemetry
{
    /** O crawler roda de hora em hora; sem varredura há mais tempo que isso, o sinal está fraco. */
    private const ONLINE_WINDOW_HOURS = 2;

    /** A faixa muda no máximo uma vez por minuto: cada visita à /sobre fazia quatro consultas (uma delas, o max(started_at) de uma tabela que só cresce). */
    private const CACHE_SECONDS = 60;

    /** @return array{sources: int, cases: int, lastSweep: ?Carbon, online: bool} */
    public function snapshot(): array
    {
        $counts = Cache::remember('secmon.telemetry', self::CACHE_SECONDS, function () {
            $lastSweep = CrawlRun::query()->max('started_at');

            return [
                'sources' => Source::query()->where('enabled', true)->count(),
                // Só as do feed público: a escondida (fora de segurança) não conta.
                'cases' => NewsCase::query()->where('is_published', true)->count(),
                'lastSweep' => $lastSweep ? Carbon::parse($lastSweep)->toIso8601String() : null,
            ];
        });

        $lastSweep = $counts['lastSweep'] ? Carbon::parse($counts['lastSweep']) : null;

        return [
            'sources' => $counts['sources'],
            'cases' => $counts['cases'],
            'lastSweep' => $lastSweep,
            // Calculado fora do cache: depende do relógio, não só da última coleta.
            'online' => $lastSweep?->gt(now()->subHours(self::ONLINE_WINDOW_HOURS)) ?? false,
        ];
    }
}
