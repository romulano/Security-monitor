<?php

namespace App\Services\Security;

use App\Models\SecurityCheck;
use App\Models\SecurityEvent;
use App\Models\SecurityFinding;
use App\Models\SecuritySite;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Grava uma varredura: a linha em security_checks, o estado de cada problema em security_findings
 * e, só quando algo muda de estado, uma linha em security_events (apareceu, piorou, foi resolvido,
 * voltou). É de lá que sai "o que piorou desde a última varredura", sem recalcular o histórico.
 */
class ScanRecorder
{
    public function record(SecuritySite $site, ScanReport $report): SecurityCheck
    {
        return DB::transaction(function () use ($site, $report) {
            $check = $site->checks()->create([
                'started_at' => now(),
                'duration_ms' => $report->durationMs,
                'http_status' => $report->httpStatus,
                'status' => $report->status,
                'score' => $report->score,
                'tls_not_after' => $report->tls?->notAfter,
                'tls_protocol' => $report->tls?->protocol,
                'results' => $report->toResults(),
                'fingerprint' => $report->fingerprint(),
                'error' => $report->error,
            ]);

            // Site fora do ar não abre nem resolve problema: nada foi verificado de fato.
            if ($report->status !== 'erro') {
                $this->track($site, $report);
            }

            $site->forceFill([
                'last_check_at' => now(),
                'next_check_at' => now()->addMinutes(max((int) config('secmon.security.min_interval_minutes'), (int) $site->interval_minutes)),
                'last_status' => $report->status,
                'last_score' => $report->score,
            ])->save();

            $this->prune($site);

            return $check;
        });
    }

    /** Uma varredura que nem chegou a rodar (exceção): fica registrada como erro, e a próxima já é agendada. */
    public function recordFailure(SecuritySite $site, string $message): SecurityCheck
    {
        return $this->record($site, new ScanReport([], 'erro', 0, null, null, 0, mb_substr($message, 0, 300)));
    }

    private function track(SecuritySite $site, ScanReport $report): void
    {
        /** @var Collection<string, SecurityFinding> $existing */
        $existing = $site->findings()->get()->keyBy(fn (SecurityFinding $f) => $f->code.'|'.$f->subject);
        $ran = [];
        $problems = [];

        foreach ($report->results as $result) {
            if ($result->status === CheckResult::UNTESTED) {
                continue;
            }

            $ran[$result->id] = true;

            if (! $result->isProblem()) {
                continue;
            }

            $problems[$result->key()] = true;
            $finding = $existing->get($result->key());

            if (! $finding) {
                $finding = $site->findings()->create([
                    'code' => $result->id,
                    'subject' => $result->subject,
                    'severity' => $result->status,
                    'status' => 'open',
                    'evidence' => $result->value,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ]);
                $this->event($site, $finding, 'opened', $result);

                continue;
            }

            $reopened = ! $finding->isOpen();
            $worsened = ! $reopened && $finding->severity === CheckResult::WARNING && $result->status === CheckResult::CRITICAL;

            $finding->forceFill([
                'severity' => $result->status,
                'status' => 'open',
                'evidence' => $result->value,
                'last_seen_at' => now(),
                'resolved_at' => null,
            ])->save();

            if ($reopened) {
                $this->event($site, $finding, 'reopened', $result);
            } elseif ($worsened) {
                $this->event($site, $finding, 'worsened', $result);
            }
        }

        // O que estava aberto, a verificação rodou e já não é problema: resolvido.
        foreach ($existing as $key => $finding) {
            if ($finding->isOpen() && isset($ran[$finding->code]) && ! isset($problems[$key])) {
                $finding->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
                $this->event($site, $finding, 'resolved', null);
            }
        }
    }

    private function event(SecuritySite $site, SecurityFinding $finding, string $kind, ?CheckResult $result): void
    {
        SecurityEvent::query()->create([
            'site_id' => $site->id,
            'finding_id' => $finding->id,
            'kind' => $kind,
            'code' => $finding->code,
            'detail' => mb_substr(trim(($finding->subject !== '' ? $finding->subject.': ' : '').($result?->value ?? '')), 0, 300) ?: null,
            'created_at' => now(),
        ]);
    }

    /** Varreduras e eventos além da retenção saem (365 dias): o histórico de um site não cresce sem fim. */
    private function prune(SecuritySite $site): void
    {
        $cutoff = now()->subDays((int) config('secmon.security.retention_days', 365));

        $site->checks()->where('started_at', '<', $cutoff)->delete();
        $site->events()->where('created_at', '<', $cutoff)->delete();
    }
}
