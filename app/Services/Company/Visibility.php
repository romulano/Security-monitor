<?php

namespace App\Services\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\CompanyMember;
use App\Models\Contracts\VisibilityRoot;
use App\Models\Contracts\VisibleThroughRoot;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Quem vê e quem configura o que se monitora (R4, R10, R14, §4.5; DESENHO-5.2 §4). Um ponto só:
 *
 * - conta pessoal: o que ela criou (`user_id`), sem empresa — como sempre foi;
 * - admin da empresa: tudo da empresa;
 * - responsável e membro: o que é de um dos seus setores e o que não tem setor;
 * - conta de empresa desativada, ou empresa suspensa ou desligada: nada.
 *
 * Configurar (entrevista, pausar, apagar...) é da admin e de quem responde pelo setor do item (marca `is_manager`,
 * papel `sector_manager` e conta ativa); o que
 * não tem setor só a admin configura (P1). Quem a conta é (o Viewer) é lido uma vez por requisição; os itens, a
 * cada consulta. A trava do banco (trigger da E2b0 e o da `sector_user`) recusa setor de
 * outra empresa; o `join` com `sectors` abaixo repete isso de propósito.
 */
class Visibility
{
    /** @var array<int, Viewer> o Viewer de cada conta, lido uma vez por requisição (ou por job da fila) */
    private array $viewers = [];

    public function __construct(private Companies $companies) {}

    /**
     * Quem a conta é para a visibilidade. Lido do banco uma vez por requisição e por conta (o serviço é `scoped`: a
     * fila o renova a cada job, e o AppServiceProvider a cada requisição): numa lista, `sees`/`manages` por item não
     * relê a empresa. Sair do setor, ser desativada ou ter a empresa suspensa vale na próxima requisição.
     */
    public function viewer(User $user): Viewer
    {
        return $this->viewers[$user->id] ??= $this->read($user);
    }

    private function read(User $user): Viewer
    {
        $member = CompanyMember::query()->with('company')->where('user_id', $user->id)->first();

        return match (true) {
            $member === null => Viewer::personal($user->id),
            $member->isDeactivated(), ! $this->companies->isActive($member->company) => Viewer::nothing($user->id, $member->company_id),
            $member->role === CompanyRole::Admin => Viewer::admin($user->id, $member->company_id),
            default => Viewer::sectors($user->id, $member->company_id),
        };
    }

    /** Restringe a consulta de uma raiz ao que a conta vê. */
    public function constrain(Builder $query, User $user): Builder
    {
        $model = $query->getModel();

        if (! $model instanceof VisibilityRoot) {
            throw new LogicException($model::class.' não é raiz da visibilidade: use o scope do filho.');
        }

        $viewer = $this->viewer($user);
        $company = $model->qualifyColumn('company_id');
        $sector = $model->qualifyColumn('sector_id');

        return match ($viewer->kind) {
            Viewer::PERSONAL => $query->where($model->qualifyColumn('user_id'), $viewer->userId)->whereNull($company),
            Viewer::ADMIN => $query->where($company, $viewer->companyId),
            Viewer::SECTORS => $query->where($company, $viewer->companyId)
                ->where(fn (Builder $q) => $q->whereNull($sector)->orWhereIn($sector, $this->sectorsOf($viewer))),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Restringe a consulta de uma raiz ao que a conta configura (R14): o que ela vê e, fora a admin, só dos setores que gerencia. */
    public function constrainManaged(Builder $query, User $user): Builder
    {
        $viewer = $this->viewer($user);
        $this->constrain($query, $user);

        return $viewer->kind === Viewer::SECTORS
            ? $query->whereIn($query->getModel()->qualifyColumn('sector_id'), $this->sectorsOf($viewer, managed: true))
            : $query;
    }

    /** A conta vê este item (raiz ou filho)? A mesma regra do scope, numa consulta. */
    public function sees(User $user, Model $item): bool
    {
        $root = $this->rootOf($item);

        return $root !== null && $root->newQuery()->whereKey($root->getKey())->visibleTo($user)->exists();
    }

    /** Pode configurar o item: o dono na conta pessoal; a admin; quem responde pelo setor dele. Sem setor, só a admin. */
    public function manages(User $user, Model $item): bool
    {
        $root = $this->rootOf($item);

        return $root !== null && $root->newQuery()->whereKey($root->getKey())->manageableBy($user)->exists();
    }

    /**
     * Quem cria e onde: as colunas de dono de uma raiz nova, para gravar com forceFill/forceCreate (as três raízes não
     * aceitam `company_id` nem `sector_id` por atribuição em massa). Conta pessoal: sem empresa nem setor. Admin: um
     * setor da empresa ou nenhum. Responsável: um setor que ele gerencia. Membro e conta sem acesso: não criam.
     *
     * @return array{user_id: int, company_id: ?int, sector_id: ?int}
     */
    public function stampFor(User $user, ?int $sectorId): array
    {
        $viewer = $this->viewer($user);

        $allowed = match ($viewer->kind) {
            Viewer::PERSONAL => $sectorId === null,
            Viewer::ADMIN => $sectorId === null || Sector::query()->whereKey($sectorId)->where('company_id', $viewer->companyId)->exists(),
            Viewer::SECTORS => $sectorId !== null && $this->sectorsOf($viewer, managed: true)->where('su.sector_id', $sectorId)->exists(),
            default => false,
        };

        if (! $allowed) {
            throw new ActionRefused(match (true) {
                $viewer->kind === Viewer::NOTHING => 'Esta conta não pode criar nada agora.',
                $viewer->kind === Viewer::SECTORS && $this->sectorChoices($user)->isEmpty() => 'Só quem responde por um setor (ou a admin) cria monitoramentos.',
                default => 'Escolha um setor que você pode usar.',
            });
        }

        return ['user_id' => $viewer->userId, 'company_id' => $viewer->companyId, 'sector_id' => $sectorId];
    }

    /** Os setores que a conta pode escolher ao criar ou trocar: a admin, todos; o responsável, os que gerencia. @return Collection<int, Sector> */
    public function sectorChoices(User $user): Collection
    {
        $viewer = $this->viewer($user);

        return match ($viewer->kind) {
            Viewer::ADMIN => Sector::query()->where('company_id', $viewer->companyId)->orderBy('name')->get(),
            Viewer::SECTORS => Sector::query()->whereIn('id', $this->sectorsOf($viewer, managed: true))->orderBy('name')->get(),
            default => new Collection,
        };
    }

    /** "Sem setor" (a empresa toda) é escolha só da admin (P1). */
    public function canUseNoSector(User $user): bool
    {
        return $this->viewer($user)->kind === Viewer::ADMIN;
    }

    /**
     * Os setores da conta dentro da empresa dela (subconsulta). Com `$managed`, só os que ela gerencia: a marca
     * `is_manager` **e** o papel `sector_manager` **e** a conta ativa, porque o banco não amarra a marca ao papel e a
     * conta desativada continua marcada (Red Team da E2a).
     */
    private function sectorsOf(Viewer $viewer, bool $managed = false): QueryBuilder
    {
        return DB::table('sector_user as su')
            ->join('sectors as s', 's.id', '=', 'su.sector_id')
            ->where('su.user_id', $viewer->userId)
            ->where('s.company_id', $viewer->companyId)
            ->when($managed, fn (QueryBuilder $q) => $q->where('su.is_manager', true)
                ->join('company_user as cu', fn ($join) => $join->on('cu.user_id', '=', 'su.user_id')->on('cu.company_id', '=', 's.company_id'))
                ->where('cu.role', CompanyRole::SectorManager->value)
                ->whereNull('cu.deactivated_at'))
            ->select('su.sector_id');
    }

    /** A raiz de onde vem a visibilidade do item (ele mesmo, se for raiz); nula se o filho não tem raiz. */
    public function rootOf(Model $item): ?Model
    {
        if ($item instanceof VisibleThroughRoot) {
            $item = $item->visibilityRoot();
        }

        if ($item !== null && ! $item instanceof VisibilityRoot) {
            throw new LogicException($item::class.' não está na visibilidade.');
        }

        return $item;
    }
}
