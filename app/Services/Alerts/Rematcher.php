<?php

namespace App\Services\Alerts;

use App\Jobs\MatchCaseToProfiles;
use App\Models\NewsCase;
use Illuminate\Support\Carbon;

/**
 * Passa casos recentes pelo matching de novo: depois de uma queda do worker,
 * ou para um perfil recém-ativado não começar com a lista vazia (aí sem
 * notificar — histórico não vira enxurrada no Slack).
 */
class Rematcher
{
    public function run(Carbon $since, ?int $profileId = null, bool $notify = false, bool $sync = false): int
    {
        $count = 0;

        NewsCase::query()
            ->where('is_published', true)
            ->where('latest_at', '>=', $since)
            ->withMax('articles', 'id')
            ->orderBy('id')
            ->chunkById(200, function ($cases) use ($profileId, $notify, $sync, &$count) {
                foreach ($cases as $case) {
                    $job = new MatchCaseToProfiles($case->id, $case->articles_max_id, 'new_case', $notify, $profileId);
                    $sync ? dispatch_sync($job) : dispatch($job);
                    $count++;
                }
            });

        return $count;
    }

    /** "24h", "7d", "90m" → instante no passado. */
    public static function since(string $window): Carbon
    {
        if (! preg_match('/^(\d+)\s*([mhd])$/', trim($window), $m)) {
            throw new \InvalidArgumentException('Use o formato 24h, 7d ou 90m.');
        }

        return match ($m[2]) {
            'm' => now()->subMinutes((int) $m[1]),
            'h' => now()->subHours((int) $m[1]),
            'd' => now()->subDays((int) $m[1]),
        };
    }
}
