<?php

namespace App\Services\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\GithubMonitor;
use App\Models\LookalikeWatch;
use App\Models\NotificationChannel;
use App\Models\OwnedDomain;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Os setores da empresa (§4.1, R3, D2, D3): quem administra cria e renomeia setores, põe e tira contas e marca quem
 * responde por cada um. Toda mudança trava a empresa antes (o teto de setores, o nome único e a conta conferidos sem
 * corrida) e entra na auditoria.
 *
 * - A admin responde por todos os setores sem linha em `sector_user` (D3): não entra em setor e não é marcada.
 * - Só a conta com o papel "Responsável do setor" é marcada responsável; quando volta a membro, perde a marca
 *   (CompanyRoles) e, quando passa a administrar, sai dos setores (a administração já responde por todos).
 * - A conta desativada fica nos setores em que estava (volta igual se for reativada), mas não entra em outro.
 */
class Sectors
{
    public const NAME_MAX = 60;

    public function __construct(private Companies $companies, private CompanyAudit $audit) {}

    /** @return array{company: Company, sectors: Collection, accounts: Collection, admin: ?User, used: int, limit: int, generalChannels: int} */
    public function overview(User $admin): array
    {
        $company = $admin->companyMembership->company;
        // As contas ativas que podem entrar num setor (a admin, não), com o papel para o botão de responsável.
        $accounts = $company->members()->with('user:id,name,email')->whereNull('deactivated_at')
            ->where('role', '!=', CompanyRole::Admin->value)->orderBy('id')->get()->keyBy('user_id');
        $sectors = $company->sectors()->orderByRaw('lower(name)')
            ->with(['users' => fn ($query) => $query->whereIn('users.id', $accounts->keys())->orderByDesc('sector_user.is_manager')->orderBy('users.name')])
            ->get();

        return [
            'company' => $company,
            'sectors' => $sectors,
            'accounts' => $accounts,
            'admin' => $company->members()->where('role', CompanyRole::Admin->value)->first()?->user,
            'used' => $sectors->count(),
            // O que sai de cada setor ao apagá-lo (o diálogo mostra; o pedido traz de volta para conferir, §4.13).
            'deletion' => $sectors->mapWithKeys(fn (Sector $sector) => [$sector->id => $this->counts($sector)])->all(),
            'limit' => $this->companies->limit($company, 'sectors'),
            // O teto dos canais gerais, para o diálogo do apagar (os canais do setor viram gerais, P5).
            'generalChannels' => $this->companies->limit($company, 'general_channels'),
        ];
    }

    public function create(User $by, string $name): Sector
    {
        return DB::transaction(function () use ($by, $name) {
            $company = $this->lockCompany($by);
            $name = $this->checkName($company, $name);
            $limit = $this->companies->limit($company, 'sectors');

            if ($company->sectors()->count() >= $limit) {
                throw new ActionRefused("A empresa já tem {$limit} setores, o máximo. Para criar outro, apague um que não use mais. Precisa de mais? Escreva para contato@example.com.");
            }

            $sector = $company->sectors()->forceCreate(['name' => $name]);
            $this->audit->record($company, $by, 'sector.created', data: ['setor' => $name, 'setor_id' => $sector->id]);

            return $sector;
        });
    }

    public function rename(User $by, Sector $sector, string $name): Sector
    {
        return DB::transaction(function () use ($by, $sector, $name) {
            $company = $this->lockCompany($by);
            $locked = $this->lockSector($company, $sector);
            $name = $this->checkName($company, $name, except: $locked);

            if ($name !== $locked->name) {
                $this->audit->record($company, $by, 'sector.renamed', data: ['de' => $locked->name, 'para' => $name, 'setor_id' => $locked->id]);
                $locked->forceFill(['name' => $name])->save();
            }

            return $locked;
        });
    }

    /** Põe no setor a conta `$memberId` (a linha em `company_user`), que precisa ser desta empresa, ativa e não a admin. */
    public function addMember(User $by, Sector $sector, int $memberId): void
    {
        DB::transaction(function () use ($by, $sector, $memberId) {
            $company = $this->lockCompany($by);
            $locked = $this->lockSector($company, $sector);
            $account = $this->lockMember($company, $memberId);

            if ($account->role === CompanyRole::Admin) {
                throw new ActionRefused('Quem administra a empresa já responde por todos os setores, sem precisar entrar em cada um.');
            }

            if ($account->isDeactivated()) {
                throw new ActionRefused('Esta conta foi removida da empresa: reative antes de adicionar a um setor.');
            }

            if ($locked->users()->whereKey($account->user_id)->exists()) {
                throw new ActionRefused("{$account->user->name} já está no setor {$locked->name}.");
            }

            $locked->users()->attach($account->user_id, ['is_manager' => false]);
            $this->audit->record($company, $by, 'sector.member_added', $account->user, data: $this->about($locked));
        });
    }

    public function removeMember(User $by, Sector $sector, CompanyMember $member): void
    {
        DB::transaction(function () use ($by, $sector, $member) {
            $company = $this->lockCompany($by);
            $locked = $this->lockSector($company, $sector);
            $account = $this->lockMember($company, $member->id);

            if ($locked->users()->detach($account->user_id) === 0) {
                throw new ActionRefused("{$account->user->name} não está no setor {$locked->name}.");
            }

            $this->audit->record($company, $by, 'sector.member_removed', $account->user, data: $this->about($locked));
        });
    }

    /** Marca (ou desmarca) a conta como responsável do setor; ela precisa estar no setor e, para marcar, ter o papel. */
    public function setManager(User $by, Sector $sector, CompanyMember $member, bool $manager): void
    {
        DB::transaction(function () use ($by, $sector, $member, $manager) {
            $company = $this->lockCompany($by);
            $locked = $this->lockSector($company, $sector);
            $account = $this->lockMember($company, $member->id);
            $current = $locked->users()->whereKey($account->user_id)->first()?->pivot
                ?? throw new ActionRefused("Adicione {$account->user->name} ao setor {$locked->name} antes.");

            if ($manager && ($account->role !== CompanyRole::SectorManager || $account->isDeactivated())) {
                throw new ActionRefused("Para responder pelo setor, {$account->user->name} precisa do papel \"Responsável do setor\", em Contas.");
            }

            if ((bool) $current->is_manager === $manager) {
                return;
            }

            $locked->users()->updateExistingPivot($account->user_id, ['is_manager' => $manager]);
            $this->audit->record($company, $by, $manager ? 'sector.manager_set' : 'sector.manager_unset', $account->user, data: $this->about($locked));
        });
    }

    /**
     * Os setores escolhidos no convite (E2a3), com a empresa já travada por quem convida: todos da empresa, senão a
     * escolha é recusada. Devolve [id => nome], na ordem do nome.
     *
     * @param  list<int>  $ids
     * @return Collection<int, string>
     */
    public function pick(Company $company, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $found = $company->sectors()->whereKey($ids)->orderByRaw('lower(name)')->pluck('name', 'id');

        if ($found->count() !== count($ids)) {
            throw new ActionRefused('Escolha setores da empresa.');
        }

        return $found;
    }

    /**
     * A conta que aceitou o convite entra nos setores dele (dentro da transação do aceite, com a empresa travada). O
     * setor apagado nesse meio-tempo fica de fora; quem entra como responsável do setor responde pelos setores do
     * convite. Devolve os nomes, para a auditoria do aceite.
     *
     * @param  list<int>  $ids
     * @return list<string>
     */
    public function enterInvited(CompanyMember $member, array $ids): array
    {
        // Só ids inteiros: um item torto na lista (gravado fora do convite) não derruba o aceite (P2 do Red Team).
        $ids = array_values(array_filter($ids, fn ($id) => is_int($id) && $id > 0));
        $sectors = Sector::query()->where('company_id', $member->company_id)->whereKey($ids)->orderByRaw('lower(name)')->get();
        $manager = $member->role === CompanyRole::SectorManager;

        foreach ($sectors as $sector) {
            $sector->users()->attach($member->user_id, ['is_manager' => $manager]);
        }

        return $sectors->pluck('name')->all();
    }

    /**
     * O papel da conta mudou (CompanyRoles, dentro da transação dela, com a linha da conta travada): como membro, deixa
     * de responder pelos setores; como admin, sai deles (D3). Cada setor entra na auditoria.
     */
    public function roleChanged(CompanyMember $member, CompanyRole $role, ?User $by): void
    {
        if ($role === CompanyRole::SectorManager) {
            return;
        }

        $user = $member->user;
        $sectors = $user->sectors()->where('sectors.company_id', $member->company_id)
            ->when($role === CompanyRole::Member, fn ($query) => $query->where('sector_user.is_manager', true))
            ->orderBy('sectors.id')->get();

        foreach ($sectors as $sector) {
            if ($role === CompanyRole::Admin) {
                $sector->users()->detach($user->id);
                $this->audit->record($member->company_id, $by, 'sector.member_removed', $user, data: $this->about($sector));
            } else {
                $sector->users()->updateExistingPivot($user->id, ['is_manager' => false]);
                $this->audit->record($member->company_id, $by, 'sector.manager_unset', $user, data: $this->about($sector));
            }
        }
    }

    /**
     * Apaga o setor (§4.13): numa transação, com a empresa travada, os alertas, Radares e monitoramentos do GitHub dele
     * ficam sem setor (a empresa toda passa a ver) e as contas saem do setor. `$expected` é a contagem que o diálogo
     * mostrou: se mudou nesse meio-tempo (alguém criou ou trocou algo no setor), recusa, para quem apaga confirmar de novo.
     * Os canais de aviso do setor viram gerais (R11), mesmo que a empresa passe dos 5 gerais: o limite só barra o próximo (P5).
     *
     * @param  array{alertas: int, radares: int, github: int, contas: int}  $expected
     * @return array{alertas: int, radares: int, github: int, contas: int}
     */
    public function delete(User $by, int $sectorId, array $expected): array
    {
        return DB::transaction(function () use ($by, $sectorId, $expected) {
            $company = $this->lockCompany($by);
            $sector = $company->sectors()->lockForUpdate()->whereKey($sectorId)->first()
                ?? throw new ActionRefused('Escolha um setor da empresa.');
            $counts = $this->counts($sector);

            if ($counts !== $expected) {
                throw new ActionRefused("O setor {$sector->name} mudou desde que você abriu a página: ".self::summary($counts).' Confira e apague de novo.');
            }

            // Sem setor antes de apagar (o nullOnDelete da E2b0 e da E3a0 é só a rede de segurança). O domínio sem setor serve
            // a todos os setores (P1 do 5.3), então nenhum item perde a posse.
            foreach ([AlertProfile::class, LookalikeWatch::class, GithubMonitor::class, OwnedDomain::class, NotificationChannel::class] as $model) {
                $model::query()->where('company_id', $company->id)->where('sector_id', $sector->id)->update(['sector_id' => null]);
            }

            $this->audit->record($company, $by, 'sector.deleted', data: ['setor' => $sector->name, 'setor_id' => $sector->id] + $counts);
            // As linhas de sector_user saem em cascata.
            $sector->delete();

            return $counts;
        });
    }

    /**
     * O que sai do setor ao apagá-lo. Os domínios (E3a2) só entram na contagem quando há algum: assim o diálogo e a
     * Atividade de um setor sem domínio continuam como antes.
     *
     * @return array{alertas: int, radares: int, github: int, contas: int, dominios?: int, canais?: int}
     */
    public function counts(Sector $sector): array
    {
        $in = fn (string $model) => $model::query()->where('company_id', $sector->company_id)->where('sector_id', $sector->id)->count();
        $domains = $in(OwnedDomain::class);
        $channels = $in(NotificationChannel::class);

        // Os domínios e os canais só aparecem quando há algum (o formulário leva a contagem em posição: com canais, os
        // domínios vão também, mesmo zero).
        return [
            'alertas' => $in(AlertProfile::class),
            'radares' => $in(LookalikeWatch::class),
            'github' => $in(GithubMonitor::class),
            'contas' => DB::table('sector_user')->where('sector_id', $sector->id)->count(),
        ] + ($domains > 0 || $channels > 0 ? ['dominios' => $domains] : []) + ($channels > 0 ? ['canais' => $channels] : []);
    }

    /** A frase da contagem: "3 alertas, 1 Radar e 2 monitoramentos do GitHub passam a ser vistos pela empresa toda; 4 contas saem do setor." */
    public static function summary(array $counts): string
    {
        $plural = fn (int $n, string $one, string $many) => $n.' '.($n === 1 ? $one : $many);
        $items = collect([
            $counts['alertas'] ? $plural($counts['alertas'], 'alerta de Notícias', 'alertas de Notícias') : null,
            $counts['radares'] ? $plural($counts['radares'], 'Radar', 'Radares') : null,
            $counts['github'] ? $plural($counts['github'], 'monitoramento do GitHub', 'monitoramentos do GitHub') : null,
            ($counts['dominios'] ?? 0) ? $plural($counts['dominios'], 'domínio', 'domínios') : null,
        ])->filter()->values();
        $total = $counts['alertas'] + $counts['radares'] + $counts['github'] + ($counts['dominios'] ?? 0);

        $first = $items->isEmpty() ? 'Nada do que se monitora está neste setor' : $items->join(', ', ' e ').($total === 1 ? ' passa' : ' passam').' a ser visto'.($total === 1 ? '' : 's').' pela empresa toda';
        $channels = $counts['canais'] ?? 0;
        $first .= $channels > 0 ? '; '.($channels === 1 ? '1 canal de aviso passa a ser geral' : $channels.' canais de aviso passam a ser gerais') : '';
        $second = match ($counts['contas']) {
            0 => 'nenhuma conta está nele',
            1 => '1 conta sai do setor',
            default => $counts['contas'].' contas saem do setor',
        };

        return "{$first}; {$second}.";
    }

    /** A empresa de quem administra, travada: o teto, o nome único e as contas conferidos sem corrida. */
    private function lockCompany(User $by): Company
    {
        $refused = new ActionRefused('Só quem administra a empresa mexe nos setores.');
        $company = Company::query()->lockForUpdate()->find($by->companyMembership()->value('company_id')) ?? throw $refused;
        // O papel é lido de novo com a empresa travada: a administração passada no meio (CompanyRoles::transfer trava
        // a empresa antes) não deixa a antiga admin mexer nos setores (L5 do Red Team).
        $membership = $company->members()->where('user_id', $by->id)->lockForUpdate()->first();

        if ($membership === null || $membership->role !== CompanyRole::Admin || $membership->isDeactivated()) {
            throw $refused;
        }

        return $company;
    }

    private function lockSector(Company $company, Sector $sector): Sector
    {
        return $company->sectors()->lockForUpdate()->whereKey($sector->id)->firstOrFail();
    }

    private function lockMember(Company $company, int $memberId): CompanyMember
    {
        return $company->members()->with('user')->lockForUpdate()->whereKey($memberId)->first()
            ?? throw new ActionRefused('Escolha uma conta da empresa.');
    }

    /**
     * O nome limpo, recusado se vazio, longo, com caractere de controle ou repetido na empresa sem diferença de caixa.
     * Antes de comparar: NFC (o "é" composto e o decomposto são o mesmo nome), sem os caracteres invisíveis de formato
     * (espaço de largura zero, marcas de direção, BOM) e com os espaços juntados — o que a tela mostra igual é igual (L3/L4).
     */
    private function checkName(Company $company, string $name, ?Sector $except = null): string
    {
        $name = (string) preg_replace('/\p{Cf}+/u', '', (string) \Normalizer::normalize($name, \Normalizer::FORM_C));
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX || preg_match('/\p{Cc}/u', $name) === 1) {
            throw ValidationException::withMessages(['name' => 'O nome do setor precisa ter de 1 a '.self::NAME_MAX.' caracteres, sem caracteres de controle.']);
        }

        $taken = $company->sectors()->whereRaw('lower(name) = lower(?)', [$name])
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))->value('name');

        if ($taken !== null) {
            throw ValidationException::withMessages(['name' => "A empresa já tem o setor {$taken}. Escolha outro nome."]);
        }

        return $name;
    }

    /** @return array{setor: string, setor_id: int} o que a auditoria guarda do setor (nada de dado pessoal) */
    private function about(Sector $sector): array
    {
        return ['setor' => $sector->name, 'setor_id' => $sector->id];
    }
}
