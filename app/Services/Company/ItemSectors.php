<?php

namespace App\Services\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Contracts\VisibilityRoot;
use App\Models\Sector;
use App\Models\User;
use App\Services\Domains\DomainOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Trocar o setor de um monitoramento da empresa (DESENHO-5.2 §2.1): quem configura o item escolhe outro setor entre os
 * que pode usar (a admin, qualquer um ou "sem setor"; o responsável, só entre os que gerencia), com o domínio dele servindo
 * ao setor novo (P1 do 5.3). Com a empresa travada,
 * e a troca entra na Atividade com o de/para. Serve às três raízes (Radar aqui; Notícias e GitHub nas PRs delas).
 */
class ItemSectors
{
    public const SECTORLESS = 'sem setor';

    public function __construct(private Visibility $visibility, private CompanyAudit $audit, private DomainOwnership $ownership) {}

    /**
     * O setor escolhido ainda existe? Chame com a empresa travada (quem apaga o setor trava a empresa antes): o que
     * nasce num setor apagado no mesmo instante recebe a mensagem, e não um erro de FK (Red Team da E2f).
     */
    public static function ensureSectorExists(?int $sectorId): void
    {
        if ($sectorId !== null && ! Sector::query()->whereKey($sectorId)->exists()) {
            throw new ActionRefused('O setor foi apagado. Escolha outro.');
        }
    }

    /**
     * Os setores (entre os que quem troca pode escolher) em que o item ficaria mudo: até a separação dos canais por setor
     * (E5b), o aviso sai pelos canais de quem criou, e quem criou só vê o item se administra a empresa ou está no setor
     * (Red Team da E2d1). "Sem setor" nunca deixa mudo (toda a empresa vê).
     *
     * @param  list<int>  $choices
     * @return list<int>
     */
    public function mutedIn(Model&VisibilityRoot $item, array $choices): array
    {
        $creator = $item->getAttribute('user_id');
        $member = CompanyMember::query()->where('company_id', $item->getAttribute('company_id'))->where('user_id', $creator)->first();

        if ($choices === []) {
            return [];
        }

        // Quem criou foi removido (desativado): já não vê nada, então o item fica mudo em qualquer setor (T1 do Red Team).
        if ($member === null || $member->isDeactivated()) {
            return array_values($choices);
        }

        if ($member->role === CompanyRole::Admin) {
            return [];
        }

        $inside = DB::table('sector_user')->where('user_id', $creator)->whereIn('sector_id', $choices)->pluck('sector_id')->all();

        return array_values(array_diff($choices, $inside));
    }

    /** @param  string  $kind  como a Atividade chama o item ("Radar"); `$label`, qual é (o domínio, o nome) */
    public function move(User $by, Model&VisibilityRoot $item, ?int $sectorId, string $kind, string $label): void
    {
        DB::transaction(function () use ($by, $item, $sectorId, $kind, $label) {
            $company = Company::query()->lockForUpdate()->find($item->getAttribute('company_id'))
                ?? throw new ActionRefused('Só o que é da empresa tem setor.');
            $locked = $item->newQuery()->lockForUpdate()->findOrFail($item->getKey());

            if (! $this->visibility->manages($by, $locked)) {
                throw new ActionRefused('Só a admin e quem responde pelo setor trocam o setor.');
            }

            // O destino segue a regra de quem cria: setor da empresa que a conta pode usar; "sem setor", só a admin.
            $this->visibility->stampFor($by, $sectorId);
            $from = $locked->getAttribute('sector_id');

            if ($from === $sectorId) {
                return;
            }

            // O domínio do item tem de servir ao setor novo, e o domínio da empresa não deixa item de outro setor sem
            // cobertura (P1 do 5.3).
            $this->ownership->ensureFitsSector($locked, $sectorId);

            $names = Sector::query()->whereKey(array_filter([$from, $sectorId]))->pluck('name', 'id');
            $locked->forceFill(['sector_id' => $sectorId])->save();
            $this->ownership->refreshSector($locked);
            $this->audit->record($company, $by, 'item.sector_changed', targetLabel: $label, data: [
                'tipo' => $kind,
                'de' => $from ? ($names[$from] ?? '?') : self::SECTORLESS,
                'para' => $sectorId ? ($names[$sectorId] ?? '?') : self::SECTORLESS,
            ]);
        });
    }
}
