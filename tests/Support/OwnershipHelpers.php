<?php

namespace Tests\Support;

use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;

/** A posse de domínio da conta (owned_domains), a fonte única que todos os serviços leem (DomainOwnership). */
trait OwnershipHelpers
{
    /** A posse do nome comprovada na conta, como o serviço grava quando acha o TXT. */
    protected function proveDomain(int $userId, string $name, string $token = 'abc123', string $source = 'radar', mixed $at = null): OwnedDomain
    {
        return OwnedDomain::query()->updateOrCreate(['user_id' => $userId, 'domain' => $name], [
            'source' => $source,
            'proof_token' => $token,
            'verified_at' => $at ?? now(),
            'checked_at' => now(),
            'check_status' => 'present',
        ]);
    }

    /** A posse de uma linha comprovada (de Notícias ou do Radar) na conta, com o token e a data dela. */
    protected function proveRow(MonitoredDomain $domain): MonitoredDomain
    {
        $this->proveDomain($domain->user_id, $domain->domain, $domain->verify_token, $domain->profile_id ? 'news' : 'radar', $domain->verified_at);

        return $domain;
    }

    /** O número da posse do domínio na conta: a opção "já comprovado" dos passos 1 do Radar e dos testes. */
    protected function provenId(MonitoredDomain $domain): int
    {
        return (int) OwnedDomain::query()->where('user_id', $domain->user_id)->where('domain', $domain->domain)->value('id');
    }
}
