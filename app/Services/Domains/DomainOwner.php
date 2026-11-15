<?php

namespace App\Services\Domains;

use App\Models\AlertProfile;
use App\Models\LookalikeWatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * De quem é a posse de um domínio (DESENHO-5.3 §2.1): de uma **conta** (a pessoal, a stack de testes e o monitor do admin)
 * ou de uma **empresa** (§4.4: a prova é o token da empresa e não depende de quem comprovou). É o único lugar que filtra
 * `owned_domains` e as cópias em `monitored_domains` pelo dono; quem decide qual é o dono é o DomainOwnership, nunca a tela.
 *
 * - Conta: `user_id` e `company_id` nulo. O `company_id is null` é a trava: a linha da empresa cadastrada por uma conta
 *   dela nunca entra no conjunto de tokens da conta.
 * - Empresa: `company_id`. `userId` é quem cadastra a linha nova (o trigger da E3a0 confere que é conta da empresa) e
 *   `sectorId`, o setor do item que pede a prova (nulo = item sem setor). A regra de compatibilidade (P1 do 5.3): o
 *   item de um setor só usa domínio sem setor ou do mesmo setor; o item sem setor, só domínio sem setor. `anySector`
 *   é quem não tem setor para comparar (o webhook, que já escolheu entre os domínios que a conta vê).
 */
final class DomainOwner
{
    private function __construct(
        public readonly int $userId,
        public readonly ?int $companyId,
        public readonly ?int $sectorId,
        public readonly bool $anySector = false,
    ) {}

    public static function account(int $userId): self
    {
        return new self($userId, null, null);
    }

    public static function company(int $companyId, int $userId, ?int $sectorId = null): self
    {
        return new self($userId, $companyId, $sectorId);
    }

    /** A empresa sem setor para comparar: vale qualquer domínio dela. */
    public static function anySectorOf(int $companyId, int $userId): self
    {
        return new self($userId, $companyId, null, true);
    }

    /** O mesmo dono sem o setor do item: a linha nova nasce sem setor (o domínio de cima achado pela busca). */
    public function withoutSector(): self
    {
        return $this->isCompany() && ! $this->anySector ? new self($this->userId, $this->companyId, null) : $this;
    }

    /** A linha de posse serve a este dono? Na conta, sempre; na empresa, pela regra de compatibilidade de setor. */
    public function fits(?int $sectorId): bool
    {
        return ! $this->isCompany() || $this->anySector || $sectorId === null || $sectorId === $this->sectorId;
    }

    /** As linhas da empresa que servem a este dono (a regra de compatibilidade em SQL). */
    public function constrainFitting(Builder $query): Builder
    {
        $this->constrain($query);

        return ! $this->isCompany() || $this->anySector ? $query
            : $query->where(fn (Builder $q) => $q->whereNull('sector_id')->when($this->sectorId !== null, fn (Builder $q) => $q->orWhere('sector_id', $this->sectorId)));
    }

    /** A empresa, se houver; senão a conta. */
    public static function of(?int $companyId, int $userId, ?int $sectorId = null): self
    {
        return $companyId === null ? self::account($userId) : self::company($companyId, $userId, $sectorId);
    }

    public function isCompany(): bool
    {
        return $this->companyId !== null;
    }

    /** As linhas de `owned_domains` deste dono. */
    public function constrain(Builder $query): Builder
    {
        return $this->isCompany()
            ? $query->where('company_id', $this->companyId)
            : $query->where('user_id', $this->userId)->whereNull('company_id');
    }

    /**
     * As cópias em `monitored_domains` (Notícias, Radar, webhook) que podem depender da posse deste dono. É um
     * superconjunto: quem aplica a posse em cada uma resolve o dono dela de novo (DomainOwnership::ownerOf).
     */
    public function copies(Builder $query): Builder
    {
        if (! $this->isCompany()) {
            return $query->where('user_id', $this->userId);
        }

        return $query->where(fn (Builder $q) => $q
            ->whereIn('profile_id', AlertProfile::query()->where('company_id', $this->companyId)->select('id'))
            ->orWhereIn('id', LookalikeWatch::query()->where('company_id', $this->companyId)->select('domain_id'))
            // Os webhooks das contas da empresa (o canal ainda é da conta até a E5a, mas a posse é da empresa).
            ->orWhereIn('id', DB::table('notification_channels')->whereNotNull('domain_id')
                ->whereIn('user_id', DB::table('company_user')->where('company_id', $this->companyId)->select('user_id'))->select('domain_id')));
    }

    /** As colunas de dono de uma linha nova de `owned_domains`. @return array{user_id: int, company_id: ?int, sector_id: ?int} */
    public function columns(): array
    {
        return ['user_id' => $this->userId, 'company_id' => $this->companyId, 'sector_id' => $this->isCompany() ? $this->sectorId : null];
    }
}
