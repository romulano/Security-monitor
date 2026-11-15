<?php

namespace App\Services\Maintenance;

use App\Services\Lookalike\CaptureIngestor;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Poda diária do que cresce sem parar (secmon:prune). Antes disso só os prints do
 * Radar tinham poda, e numa janela de 15 minutos de um `if` do agendador; sessões
 * dependiam do sorteio de 2% por requisição, e entregas, execuções do crawler e jobs
 * que falharam nunca saíam do banco.
 *
 * Cada item apaga em lotes de 5 mil, para não segurar a tabela, e um prazo 0 no config
 * (secmon.retention.*) desliga a poda dele. Em simulação (`$dryRun`) só conta.
 */
class RetentionPruner
{
    private const BATCH = 5000;

    public function __construct(private CaptureIngestor $captures) {}

    /**
     * @return array<string, int> o que foi (ou seria) apagado, por item
     */
    public function run(bool $dryRun = false): array
    {
        $counts = [];

        // Sessões vencidas: a /privacidade promete que expiram depois de 4 h sem uso.
        $cutoff = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();
        $counts['sessões vencidas'] = $this->delete('sessions', fn (Builder $q) => $q->where('last_activity', '<', $cutoff), $dryRun);

        if ($days = (int) config('secmon.retention.crawl_runs_days')) {
            $counts["execuções do crawler com mais de {$days} dias"] = $this->delete('crawl_runs', fn (Builder $q) => $q->where('started_at', '<', now()->subDays($days)), $dryRun);
        }

        if ($days = (int) config('secmon.retention.article_attempts_days')) {
            $counts["tentativas de análise com mais de {$days} dias"] = $this->delete('article_attempts', fn (Builder $q) => $q->where('created_at', '<', now()->subDays($days)), $dryRun);
        }

        if ($days = (int) config('secmon.retention.failed_jobs_days')) {
            $counts["jobs que falharam há mais de {$days} dias"] = $this->delete('failed_jobs', fn (Builder $q) => $q->where('failed_at', '<', now()->subDays($days)), $dryRun);
        }

        if ($days = (int) config('secmon.retention.delivery_payload_days')) {
            $counts["entregas com o texto enviado há mais de {$days} dias (texto apagado)"] = $this->scrubDeliveries($days, $dryRun);
        }

        if ($days = (int) config('secmon.retention.delivery_days')) {
            $counts["entregas com mais de {$days} dias"] = $this->delete('notification_deliveries', fn (Builder $q) => $q->where('created_at', '<', now()->subDays($days)), $dryRun);
        }

        if ($days = (int) config('secmon.retention.email_messages_days')) {
            $counts["e-mails enviados há mais de {$days} dias"] = $this->delete('email_messages', fn (Builder $q) => $q->where('created_at', '<', now()->subDays($days)), $dryRun);
        }

        if ($days = (int) config('secmon.retention.page_views_days')) {
            $counts["acessos ao site com mais de {$days} dias"] = $this->delete('page_views', fn (Builder $q) => $q->where('created_at', '<', now()->subDays($days)), $dryRun);
        }

        if ($days = (int) config('secmon.retention.device_sightings_days')) {
            $counts["códigos de aparelho sem aparecer há mais de {$days} dias"] = $this->delete('device_sightings', fn (Builder $q) => $q->where('last_seen', '<', now()->subDays($days)), $dryRun);
        }

        if ($days = (int) config('secmon.retention.access_events_days')) {
            $counts["eventos de acesso recusado com mais de {$days} dias"] = $this->delete('access_events', fn (Builder $q) => $q->where('created_at', '<', now()->subDays($days)), $dryRun);
            // A contagem do que passou do teto: uma linha por minuto, sem id, apagada de uma vez.
            $overflow = DB::table('access_event_overflow')->where('minute', '<', now()->subDays($days));
            $counts["contagens de eventos acima do teto com mais de {$days} dias"] = $dryRun ? $overflow->count() : $overflow->delete();
        }

        // Prints do Radar: os que passaram de keep_days e não marcaram nada (ver CaptureIngestor::prune).
        if (! $dryRun) {
            $counts['prints do Radar velhos'] = $this->captures->prune();
        }

        return $counts;
    }

    /** Tira o conteúdo (payload) de entregas antigas e deixa o registro de que houve envio. */
    private function scrubDeliveries(int $days, bool $dryRun): int
    {
        $where = fn (Builder $q) => $q->where('created_at', '<', now()->subDays($days))->whereNotNull('payload');

        if ($dryRun) {
            return $where(DB::table('notification_deliveries'))->count();
        }

        $total = 0;

        do {
            $ids = $where(DB::table('notification_deliveries'))->orderBy('id')->limit(self::BATCH)->pluck('id');
            $total += $ids->isEmpty() ? 0 : DB::table('notification_deliveries')->whereIn('id', $ids)->update(['payload' => null]);
        } while ($ids->count() === self::BATCH);

        return $total;
    }

    /** @param  Closure(Builder): Builder  $where */
    private function delete(string $table, Closure $where, bool $dryRun): int
    {
        if ($dryRun) {
            return $where(DB::table($table))->count();
        }

        $total = 0;

        do {
            $ids = $where(DB::table($table))->orderBy('id')->limit(self::BATCH)->pluck('id');
            $total += $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::BATCH);

        return $total;
    }
}
