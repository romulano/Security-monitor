<?php

namespace App\Services\Domains;

use Illuminate\Database\Eloquent\Builder;

/**
 * De quem é a posse de um domínio: da conta que o comprovou. É o único lugar que filtra `owned_domains` e as cópias
 * em `monitored_domains` pelo dono; quem decide qual é o dono é o DomainOwnership, nunca a tela.
 *
 * Na versão aberta não existe empresa, então há um tipo de dono só. A classe continua existindo porque é ela que
 * guarda, num lugar só, como se filtra por dono — e os chamadores não precisam saber disso.
 */
final class DomainOwner
{
    private function __construct(public readonly int $userId) {}

    public static function account(int $userId): self
    {
        return new self($userId);
    }

    /** Antes decidia entre empresa e conta; hoje é sempre a conta. */
    public static function of(int $userId): self
    {
        return self::account($userId);
    }

    /** A linha de posse serve a este dono? Sem setor para comparar, sempre. */
    public function fits(?int $sectorId = null): bool
    {
        return true;
    }

    /** As linhas de `owned_domains` deste dono. */
    public function constrain(Builder $query): Builder
    {
        return $query->where('user_id', $this->userId);
    }

    /** O mesmo que `constrain`: a regra de compatibilidade de setor era do plano empresarial. */
    public function constrainFitting(Builder $query): Builder
    {
        return $this->constrain($query);
    }

    /**
     * As cópias em `monitored_domains` (Notícias, Radar, webhook) que podem depender da posse deste dono. É um
     * superconjunto: quem aplica a posse em cada uma resolve o dono dela de novo (DomainOwnership::ownerOf).
     */
    public function copies(Builder $query): Builder
    {
        return $query->where('user_id', $this->userId);
    }

    /** As colunas de dono de uma linha nova de `owned_domains`. @return array{user_id: int} */
    public function columns(): array
    {
        return ['user_id' => $this->userId];
    }
}
