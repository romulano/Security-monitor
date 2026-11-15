<?php

namespace App\Services\Domains;

use App\Models\MonitoredDomain;
use Illuminate\Support\Facades\DB;

/**
 * Os tokens do TXT secmon-verify=. A posse inteira depende de **um token pertencer a um dono só**: o TXT é público no
 * DNS, então um token que entrasse no conjunto de outro dono provaria para ele os domínios do dono de verdade. Daqui
 * saem o token novo e a conferência do token que um serviço quer acrescentar a uma conta (`DomainOwnership::claim`).
 */
class DomainTokens
{
    /** Um token que ninguém usa: nem conta, nem empresa, nem linha de posse, nem cópia de serviço, nem o monitor. */
    public function fresh(): string
    {
        do {
            $token = MonitoredDomain::newToken();
        } while ($this->used($token));

        return $token;
    }

    /** O token já aparece em algum lugar (de qualquer dono)? */
    public function used(string $token): bool
    {
        return DB::table('users')->where('domain_token', $token)->exists()
            || $this->inOwnedDomains($token)->exists()
            || DB::table('monitored_domains')->where('verify_token', $token)->exists()
            || DB::table('security_sites')->where('verify_token', $token)->exists();
    }

    /**
     * O token é de outro dono que não a conta? O de outra conta (`users.domain_token`), ou um que outra conta já tem
     * nas linhas de posse. A conta pode adotar só o que é dela ou de ninguém.
     */
    public function ownedByOther(string $token, int $userId): bool
    {
        return DB::table('users')->where('domain_token', $token)->where('id', '!=', $userId)->exists()
            || $this->inOwnedDomains($token)->where('user_id', '!=', $userId)->exists();
    }

    /**
     * O token é o de outra conta (`users.domain_token`)? A régua do `check()` (X1 do Red Team da E3a1). Mais curta que
     * a do `ownedByOther`: o token legado de uma linha de outra conta ainda vale no `$extra` (os testes do monitor de
     * sites usam o mesmo token fixo em várias contas admin); nenhum caminho grava hoje esse token numa cópia.
     */
    public function heldByAnother(string $token, int $userId): bool
    {
        return DB::table('users')->where('domain_token', $token)->where('id', '!=', $userId)->exists();
    }

    private function inOwnedDomains(string $token)
    {
        return DB::table('owned_domains')->where(fn ($q) => $q->where('proof_token', $token)->orWhereRaw('tokens @> ?::jsonb', [json_encode([$token])]));
    }
}
