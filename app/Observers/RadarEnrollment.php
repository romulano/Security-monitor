<?php

namespace App\Observers;

use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;

/**
 * Radar de domínios e o domínio dele. O Radar nasce na entrevista dele
 * (Integração), não sozinho. Instalação de um dono só: o domínio já nasce
 * comprovado, então não há mais a espera pelo registro TXT; este observador
 * cuida só de apagar os prints quando o aceite é revogado.
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
    }

    /** O banco apaga o Radar em cascata; os prints no disco saem aqui. */
    public function deleting(MonitoredDomain $domain): void
    {
        LookalikeWatch::query()->where('domain_id', $domain->id)->first()?->deleteFiles();
    }
}
