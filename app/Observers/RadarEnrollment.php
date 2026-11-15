<?php

namespace App\Observers;

use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;

/**
 * Radar de domínios e a posse do domínio. O Radar nasce na entrevista dele
 * (Integração), não sozinho: aqui, o Radar ativado que esperava o registro TXT
 * começa quando a posse é comprovada, e o que perde a comprovação volta a
 * esperar (com o histórico guardado). Ninguém monitora imitações de um domínio
 * que não é seu.
 *
 * Só marca a primeira varredura para já: o agendador (a cada 15 minutos)
 * a faz, longe da requisição que comprovou o domínio.
 */
class RadarEnrollment
{
    public function saved(MonitoredDomain $domain): void
    {
        $watch = LookalikeWatch::query()->where('domain_id', $domain->id)->first();

        if (! $watch) {
            return;
        }

        // Revogou o aceite: os prints do site do cliente saem junto.
        if ($domain->wasChanged('consent_at') && ! $domain->consent_at) {
            $watch->deleteFiles(referenceOnly: true);
            $watch->forceFill(['reference' => [], 'reference_at' => null, 'reference_status' => 'no_consent'])->save();
        }

        if (! $domain->wasChanged('verified_at')) {
            return;
        }

        if ($domain->isVerified() && $watch->isWaiting()) {
            $watch->update(['status' => 'active', 'next_scan_at' => now()]);
        } elseif (! $domain->isVerified() && $watch->isActive()) {
            $watch->update(['status' => 'waiting']);
        }
    }

    /** O banco apaga o Radar em cascata; os prints no disco saem aqui. */
    public function deleting(MonitoredDomain $domain): void
    {
        LookalikeWatch::query()->where('domain_id', $domain->id)->first()?->deleteFiles();
    }
}
