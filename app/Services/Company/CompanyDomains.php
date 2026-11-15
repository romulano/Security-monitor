<?php

namespace App\Services\Company;

use App\Exceptions\ActionRefused;
use App\Models\Company;
use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Domains\CompanySubdomains;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;
use App\Support\DomainName;
use App\Support\RegistrableDomain;
use Illuminate\Support\Facades\DB;

/**
 * A tela Domínios da empresa (DESENHO-5.3 §2.7): os domínios que a conta vê (R10), com o setor e a posse; quem configura
 * (a admin; o responsável, os do setor dele) cadastra, comprova, troca o setor e remove. O TXT da empresa aparece só a
 * quem configura (P10). A posse é do DomainOwnership: aqui só a tela, o limite e a Atividade.
 */
class CompanyDomains
{
    public const SOURCE = 'company';

    public function __construct(
        private Visibility $visibility,
        private DomainOwnership $ownership,
        private ItemSectors $sectors,
        private Companies $companies,
        private CompanyAudit $audit,
        private DnsResolver $dns,
        private CompanyDomainNotices $notices,
        private CompanySubdomains $subdomains,
    ) {}

    /** @return array<string, mixed> o que a tela mostra */
    public function overview(User $user): array
    {
        $company = $user->companyMembership->company;
        $managed = OwnedDomain::query()->manageableBy($user)->pluck('id')->all();
        $choices = $this->visibility->sectorChoices($user);
        $domains = OwnedDomain::query()->visibleTo($user)->with('sector')->orderBy('domain')->get()
            ->each(function (OwnedDomain $owned) use ($user, $managed, $choices) {
                $manages = in_array($owned->id, $managed, true);
                $state = $this->ownership->screen($this->ownership->ownerOfOwned($owned), $owned->domain, $this->ownership->txtToken($this->ownership->ownerOfOwned($owned), $owned->domain));
                $owned->setAttribute('manages', $manages);
                // P10: o TXT da empresa só para quem configura; quem só vê fica com o estado.
                $owned->setAttribute('ownership', $manages ? $state : array_diff_key($state, ['txt' => true]));
                $owned->setAttribute('dependents', $manages ? $this->dependents($owned) : null);
                // Os setores para onde o domínio não pode ir: deixaria alerta ou Radar de outro setor sem a posse (P1).
                // Os subdomínios dele (E3b): a lista, o uso contra o teto e se a conta cadastra e tira.
                $owned->setAttribute('subs', $this->subdomains->screen($user, $owned));
                // O nome antigo cadastrado como domínio embaixo de outro da empresa: dá para tirar e cadastrar como
                // subdomínio, liberando a vaga (Designer, rodada 13).
                $owned->setAttribute('coveredBy', $manages ? $this->ownership->covering(DomainOwner::anySectorOf($owned->company_id, $owned->user_id), $owned->domain)?->domain : null);
                $owned->setAttribute('blocked', $manages ? $choices->mapWithKeys(fn ($sector) => [$sector->id => $this->ownership->blockingItems($owned, $sector->id)])->filter()->all() : []);
            });

        return [
            'company' => $company,
            'domains' => $domains,
            'used' => $this->used($company),
            'limit' => $this->companies->limit($company, 'domains'),
            'sectorChoices' => $choices,
            'sectorless' => $this->visibility->canUseNoSector($user),
            'canAdd' => $choices->isNotEmpty() || $this->visibility->canUseNoSector($user),
        ];
    }

    /** Cadastra o nome na empresa, no setor escolhido (Visibility::stampFor), sem prova: a tela mostra o TXT. */
    public function add(User $by, string $typed, ?int $sectorId): OwnedDomain
    {
        $name = DomainName::normalize($typed);

        if ($name === null || RegistrableDomain::of($name) === null) {
            throw new ActionRefused('Informe um domínio válido, como empresa.com.br (sem IP, porta ou domínio interno).');
        }

        return DB::transaction(function () use ($by, $name, $sectorId) {
            $stamp = $this->visibility->stampFor($by, $sectorId);
            $company = Company::query()->findOrFail($stamp['company_id']);
            ItemSectors::ensureSectorExists($sectorId);
            $existing = OwnedDomain::query()->where('company_id', $company->id)->where('domain', $name)->first();

            if ($existing !== null) {
                // Sem dizer o setor: quem cadastra pode não ver o domínio (R10).
                throw new ActionRefused($this->visibility->sees($by, $existing)
                    ? "{$name} já está na lista."
                    : "{$name} já está cadastrado na empresa, num setor que você não vê. Peça a quem administra a empresa.");
            }

            // O limite de domínios (com a empresa travada) e a regra de setor são do claim (E3a2); o nome abaixo de um domínio
            // da empresa é subdomínio, não domínio novo: "Já coberto por X. Cadastre como subdomínio." (E3b, Designer).
            $owned = $this->ownership->claimNew(DomainOwnership::ownerOfStamp($stamp), $name, self::SOURCE);
            $this->audit->record($company, $by, 'domain.added', targetLabel: $name, data: ['setor' => $this->sectorName($owned)]);

            return $owned;
        });
    }

    /**
     * "Verificar agora": o TXT da empresa no nome ou num de cima, ao vivo. A posse comprovada com o TXT sumido é
     * conferida de novo. Sem o TXT, recusa dizendo o que falta. Devolve a prova.
     */
    public function verify(User $by, OwnedDomain $owned): OwnedDomain
    {
        $this->mustManage($by, $owned);
        $owner = $this->ownership->ownerOfOwned($owned);
        $txt = DomainOwnership::PREFIX.$this->ownership->txtToken($owner, $owned->domain);

        if ($owned->isVerified()) {
            if ($owned->unseen_since !== null && $this->ownership->recheck($owned) !== true) {
                throw new ActionRefused("Ainda não encontramos o TXT {$txt} em {$owned->domain}. Registros novos podem levar alguns minutos para propagar.");
            }

            return $owned->refresh();
        }

        [$proof, $found] = $this->ownership->check($owner, $owned->domain, $owned->source);

        if ($proof !== null) {
            return $proof;
        }

        // Num apelido (CNAME) o TXT nunca vai aparecer: o que aparece nele é o do destino (a #75).
        if ($target = $this->dns->cname($owned->domain)[0] ?? null) {
            throw new ActionRefused("{$owned->domain} é um apelido (CNAME) de {$target}. Um apelido não tem TXT próprio e não prova que o nome é da empresa. Crie o TXT em "
                .(RegistrableDomain::of($owned->domain) ?? $owned->domain).'.');
        }

        throw new ActionRefused($found === null
            ? "O DNS de {$owned->domain} não respondeu agora. Tente de novo em alguns minutos."
            : "Ainda não encontramos o TXT {$txt} em {$owned->domain}. Registros novos podem levar alguns minutos para propagar.");
    }

    /** Trocar o setor (ItemSectors: quem configura, para um setor que pode usar e que não deixa item sem posse; a Atividade guarda o de/para). */
    public function move(User $by, OwnedDomain $owned, ?int $sectorId): void
    {
        // A regra de setor (o domínio não deixa item de outro setor sem cobertura) e a posse das cópias depois, no move (E3a2).
        $this->sectors->move($by, $owned, $sectorId, 'Domínio', $owned->domain);
    }

    /**
     * Remover: a linha da posse sai e as cópias dos serviços que dependiam dela perdem a posse na mesma transação. A
     * contagem que o diálogo mostrou volta no pedido: se mudou, recusa para confirmar de novo (como apagar setor).
     *
     * @param  array{alertas: int, radares: int, webhooks: int}  $expected
     * @return array{alertas: int, radares: int, webhooks: int}
     */
    public function remove(User $by, int $ownedId, array $expected): array
    {
        $counts = DB::transaction(function () use ($by, $ownedId, $expected) {
            $company = Company::query()->lockForUpdate()->findOrFail($by->companyMembership?->company_id);
            $owned = OwnedDomain::query()->manageableBy($by)->lockForUpdate()->find($ownedId)
                ?? throw new ActionRefused('Escolha um domínio que você configura.');
            $counts = $this->dependents($owned);

            if ($counts !== $expected) {
                throw new ActionRefused("O que depende de {$owned->domain} mudou desde que você abriu a página: ".self::summary($counts).' Confira e remova de novo.');
            }

            $owner = $this->ownership->ownerOfOwned($owned);
            $this->audit->record($company, $by, 'domain.removed', targetLabel: $owned->domain, data: ['setor' => $this->sectorName($owned)] + $counts);
            $owned->delete();
            $this->ownership->sync($owner, $owned->domain);

            return $counts;
        });

        // Abaixo dos 80% ou dos 100% do limite de novo: os avisos desses marcos voltam a valer (E3d).
        $this->notices->domains((int) $by->companyMembership->company_id);

        return $counts;
    }

    /**
     * Quantos alertas, Radares e webhooks da empresa perdem a posse se o domínio sair: as cópias comprovadas, do nome
     * ou de um subdomínio dele, cuja prova é esta linha e que nenhum outro domínio comprovado que serve a elas cobre (um
     * de cima, H1). Só números: o item pode ser de um setor que quem remove não vê.
     *
     * @return array{alertas: int, radares: int, webhooks: int}
     */
    public function dependents(OwnedDomain $owned): array
    {
        $counts = ['alertas' => 0, 'radares' => 0, 'webhooks' => 0];

        $this->ownership->ownerOfOwned($owned)->copies(MonitoredDomain::query())
            ->whereDoesntHave('pentestDomain')
            ->whereNotNull('verified_at')
            ->with(['profile', 'lookalikeWatch'])
            ->get()
            ->filter(fn (MonitoredDomain $row) => $row->domain === $owned->domain || str_ends_with($row->domain, '.'.$owned->domain))
            ->filter(function (MonitoredDomain $row) use ($owned) {
                $owner = $this->ownership->ownerOf($row);

                // H1 do Red Team: um domínio de cima que serve ao item (sem setor ou do setor dele) continua provando.
                return (bool) $this->ownership->proof($owner, $row->domain)?->is($owned)
                    && ! $owner->constrainFitting(OwnedDomain::query())->whereNotNull('verified_at')->whereKeyNot($owned->id)
                        ->whereIn('domain', DomainOwnership::selfAndParents($row->domain))->exists();
            })
            ->each(function (MonitoredDomain $row) use (&$counts) {
                $counts[match (true) {
                    $row->profile_id !== null => 'alertas',
                    $row->lookalikeWatch !== null => 'radares',
                    default => 'webhooks',
                }]++;
            });

        return $counts;
    }

    /** "1 alerta de Notícias e 2 Radares perdem a posse." / "Nada deixa de funcionar." */
    public static function summary(array $counts): string
    {
        $parts = array_filter([
            $counts['alertas'] ? $counts['alertas'].' '.($counts['alertas'] === 1 ? 'alerta de Notícias' : 'alertas de Notícias') : null,
            $counts['radares'] ? $counts['radares'].' '.($counts['radares'] === 1 ? 'Radar' : 'Radares') : null,
            $counts['webhooks'] ? $counts['webhooks'].' '.($counts['webhooks'] === 1 ? 'webhook' : 'webhooks') : null,
        ]);

        if ($parts === []) {
            return 'Nada da empresa depende da posse deste domínio agora. Nada é apagado.';
        }

        $last = array_pop($parts);
        $total = $counts['alertas'] + $counts['radares'] + $counts['webhooks'];

        return ($parts ? implode(', ', $parts).' e '.$last : $last).($total === 1 ? ' perde' : ' perdem').' a posse e '.($total === 1 ? 'para' : 'param').' de vigiar este domínio até ele ser comprovado de novo. Nada é apagado.';
    }

    /** Toda linha da empresa conta, pendente ou comprovada (§2.4): a espera do TXT também custa. */
    private function used(Company $company): int
    {
        return OwnedDomain::query()->where('company_id', $company->id)->count();
    }

    private function mustManage(User $by, OwnedDomain $owned): void
    {
        if (! $this->visibility->manages($by, $owned)) {
            throw new ActionRefused('Só a admin e quem responde pelo setor do domínio fazem isso.');
        }
    }

    private function sectorName(OwnedDomain $owned): string
    {
        return $owned->sector_id ? ($owned->sector()->value('name') ?? '?') : ItemSectors::SECTORLESS;
    }
}
