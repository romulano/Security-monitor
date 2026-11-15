<?php

namespace App\Services\Domains;

use App\Exceptions\ActionRefused;
use App\Jobs\DiscoverDomainStack;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Company\Companies;
use App\Services\Company\CompanyDomainNotices;
use App\Support\RegistrableDomain;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * A posse de domínio, uma só para o dono (`owned_domains`): comprovada num serviço, nenhum outro
 * pede de novo. Notícias, o Radar de domínios, o monitor de sites e o webhook usam só daqui. O dono
 * (DomainOwner) é a conta ou, no plano empresarial, a empresa (DESENHO-5.3 §2): a posse da empresa
 * se prova só com o token da empresa e não depende de quem comprovou (§4.4).
 *
 * As regras valem para todos:
 * - a prova é o TXT secmon-verify=<token> no próprio nome. O que chega por um CNAME é do destino, e o
 *   CNAME se pergunta antes (DnsResolver::txtOwnedOrNull, a #75): um apelido nunca prova a posse;
 * - qualquer token da conta prova: o da conta (`users.domain_token`, o de todo nome novo) e os de antes
 *   da unificação, que continuam valendo (ninguém troca o TXT que já publicou);
 * - o nome comprovado cobre os subdomínios. Para comprovar um subdomínio vale o TXT nele ou num domínio
 *   de cima, até o principal (como o monitor de sites já fazia);
 * - a posse comprovada é conferida de novo uma vez por dia (o `secmon:alerts-verify-domains`) e cai
 *   quando o TXT fica 7 dias sem aparecer, com o DNS mudo contando junto. Cair desliga em cada serviço
 *   só o que dependia dela (sync()), e o agendador volta a procurar o TXT por 72 h. A prova de antes da
 *   unificação (`legacy`) não cai sozinha até o TXT ser visto de novo: o Radar dizia que dava para
 *   apagar o registro depois de comprovar.
 *
 * A stack de testes de segurança tem regra própria, mais forte (Red Team e Blue Team): a cópia dela
 * (MonitoredDomain com `pentestDomain`) confere só o token da cópia, no próprio nome, ao vivo, no
 * começo de cada fase e antes de cada ação, com auditoria; domínio de cima não vale lá. Ela lê daqui
 * a prova do mesmo nome, para não pedir o TXT de novo, e grava aqui o que acha (os métodos *Copy).
 */
class DomainOwnership
{
    public const PREFIX = 'secmon-verify=';

    /** A recusa da regra de compatibilidade de setor (P1 do 5.3): diz que o nome é da empresa, nunca o setor. */
    public const OTHER_SECTOR = 'Este domínio já está cadastrado na empresa, num setor que este item não usa. Peça à admin.';

    public function __construct(private DnsResolver $dns, private DomainTokens $registry, private CompanyDomainNotices $notices) {}

    /**
     * O TXT do próprio nome tem um dos tokens? Devolve o token achado; falso, não tem; nulo, o DNS
     * não respondeu ("não sei" é diferente de "sumiu"). Toda prova de posse do Security Monitor passa por aqui.
     *
     * @param  list<?string>  $tokens
     */
    public function find(string $name, array $tokens): string|false|null
    {
        $records = $this->dns->txtOwnedOrNull($name);

        if ($records === null) {
            return null;
        }

        $records = array_map('trim', $records);

        foreach (self::clean($tokens) as $token) {
            if (in_array(self::PREFIX.$token, $records, true)) {
                return $token;
            }
        }

        return false;
    }

    // ---- a conta ---------------------------------------------------------------

    /**
     * O token da conta, o do TXT de todo nome novo. Na primeira vez, é o que a conta já usa (o da prova
     * mais antiga, como na migração: um registro serve a todos os serviços) ou um novo.
     */
    public function token(User $user): string
    {
        if ($user->domain_token) {
            return $user->domain_token;
        }

        $used = DomainOwner::account($user->id)->constrain(OwnedDomain::query())->whereNotNull('proof_token')->orderByRaw('verified_at asc nulls last')->orderBy('id')->value('proof_token');
        // O da prova só vira o da conta se não for de outro dono (outra conta ou uma empresa, DESENHO-5.3 §2.3).
        $taken = $used !== null && $this->registry->ownedByOther($used, $user->id);

        // Dois pedidos juntos: só o primeiro grava, e os dois leem o mesmo.
        DB::table('users')->where('id', $user->id)->whereNull('domain_token')->update(['domain_token' => $used && ! $taken ? $used : $this->registry->fresh()]);
        $token = (string) DB::table('users')->where('id', $user->id)->value('domain_token');
        $user->forceFill(['domain_token' => $token])->syncOriginalAttribute('domain_token');

        return $token;
    }

    /**
     * Todo token que prova a posse para o dono. Conta: o dela e os de antes da unificação, de qualquer nome (só das
     * linhas pessoais: a linha da empresa cadastrada por ela não entra). Valer em qualquer nome não abre nada: o TXT
     * tem de estar no nome, e só esta conta usa esses tokens. Empresa: **só** o token da empresa (sem legado: nada
     * migra para a empresa, R12).
     *
     * @return list<string>
     */
    public function tokens(DomainOwner $owner): array
    {
        if ($owner->isCompany()) {
            return self::clean([DB::table('companies')->where('id', $owner->companyId)->value('domain_token')]);
        }

        $tokens = [DB::table('users')->where('id', $owner->userId)->value('domain_token')];

        foreach ($owner->constrain(OwnedDomain::query())->get(['tokens', 'proof_token']) as $owned) {
            array_push($tokens, $owned->proof_token, ...($owned->tokens ?? []));
        }

        return self::clean($tokens);
    }

    /**
     * O token que a tela de um serviço mostra para um nome novo. Conta: o da prova do nome, o de antes dele ou o da
     * conta. Empresa: sempre o da empresa (o TXT não depende de quem comprovou).
     */
    public function txtToken(DomainOwner $owner, string $name): string
    {
        if ($owner->isCompany()) {
            return $this->tokens($owner)[0];
        }

        $owned = $this->owned($owner, $name);

        return $owned?->proof_token ?? ($owned?->tokens[0] ?? null) ?? $this->token(User::query()->findOrFail($owner->userId));
    }

    /**
     * O nome no dono (nasce sem prova). `$token` é o que a tela do serviço já mostrou para ele: na conta, se não é
     * dela, passa a valer também (a linha criada pelo código de antes, no meio do deploy), **a não ser** que seja de
     * outro dono (outra conta ou uma empresa: o token de outro dono nunca entra, DESENHO-5.3 §2.3). Na empresa, nada de
     * fora entra: só o token da empresa prova.
     */
    public function claim(DomainOwner $owner, string $name, string $source, ?string $token = null): OwnedDomain
    {
        // Na empresa, o nome de baixo de um domínio que ela já tem não vira domínio novo (não gasta um dos 15): o item
        // usa a linha de cima, que cobre os subdomínios (E3b).
        $owned = $this->owned($owner, $name) ?? ($owner->isCompany() ? $this->covering($owner, $name) : null) ?? $this->create($owner, $name, $source);

        // P1 do 5.3: o nome já é da empresa num setor que este item não usa. A recusa não diz qual setor.
        if (! $owner->fits($owned->sector_id)) {
            throw new ActionRefused(self::OTHER_SECTOR);
        }

        if ($owner->isCompany() || $token === null || $token === '' || in_array($token, $this->tokens($owner), true)) {
            return $owned;
        }

        if ($this->registry->ownedByOther($token, $owner->userId)) {
            Log::warning('Posse: token de outro dono recusado na conta', ['user_id' => $owner->userId, 'domain' => $name, 'source' => $source]);

            return $owned;
        }

        $owned->forceFill(['tokens' => [...($owned->tokens ?? []), $token]])->save();

        return $owned;
    }

    /**
     * O cadastro de um domínio na tela de domínios da empresa (E3a3): o nome não pode já estar na empresa, nem abaixo
     * de um domínio dela (esse é subdomínio, decisão do Designer na E3b). O limite e o setor seguem o claim().
     */
    public function claimNew(DomainOwner $owner, string $name, string $source): OwnedDomain
    {
        if (! $owner->isCompany()) {
            throw new LogicException('O cadastro pela tela de domínios é só da empresa.');
        }

        if ($this->owned($owner, $name) !== null) {
            throw new ActionRefused("{$name} já está entre os domínios da empresa.");
        }

        if (($parent = $this->covering($owner, $name)) !== null) {
            throw new ActionRefused("Já coberto por {$parent->domain}. Cadastre como subdomínio.");
        }

        return $this->claim($owner, $name, $source);
    }

    /**
     * A linha da empresa de um domínio acima do nome (o mais próximo), que o cobre; nula se não há. Só a que serve ao setor
     * do item (P1): a de outro setor não cobre, e o item fica com a linha própria do nome (Y1 do Red Team da E3b1).
     */
    public function covering(DomainOwner $owner, string $name): ?OwnedDomain
    {
        $parents = array_slice(self::selfAndParents($name), 1);

        return $parents === [] ? null : $owner->constrainFitting(OwnedDomain::query())->whereIn('domain', $parents)->get()
            ->sortByDesc(fn (OwnedDomain $owned) => strlen($owned->domain))->first();
    }

    /** O registro comprovado que vale para o nome: o dele ou o do domínio de cima mais próximo. Sem consulta ao DNS. */
    public function proof(DomainOwner $owner, string $name): ?OwnedDomain
    {
        // Na empresa, só as linhas que servem ao setor do item (P1 do 5.3): sem setor ou do mesmo setor.
        return $owner->constrainFitting(OwnedDomain::query())
            ->whereNotNull('verified_at')
            ->whereIn('domain', self::selfAndParents($name))
            ->get()
            ->sortByDesc(fn (OwnedDomain $owned) => strlen($owned->domain))
            ->first();
    }

    /**
     * A prova que a stack de testes lê: só a do próprio nome (a de um domínio de cima não vale lá) e em
     * dia (a que a reconferência não achou mais no DNS fica de fora: a stack pararia na 1ª conferência).
     */
    public function exactProof(DomainOwner $owner, string $name): ?OwnedDomain
    {
        $owned = $this->owned($owner, $name);

        return $owned?->isVerified() && $owned->unseen_since === null ? $owned : null;
    }

    /**
     * Os nomes comprovados que a conta vê (Visibility: na conta pessoal, os dela; na empresa, os da empresa que ela vê),
     * do mais antigo ao mais novo, cada um com `origin` (de onde veio a prova): o passo 1 do Radar oferece reaproveitar.
     *
     * @return Collection<int, OwnedDomain>
     */
    public function proven(User $user): Collection
    {
        return OwnedDomain::query()
            ->visibleTo($user)
            ->whereNotNull('verified_at')
            ->oldest('id')
            ->get()
            ->each(fn (OwnedDomain $owned) => $owned->setAttribute('origin', $this->origin($owned)));
    }

    /**
     * Os nomes comprovados de um dono, do mais antigo ao mais novo, com `origin`: o passo 1 dos testes de segurança
     * (sempre a conta: a stack fica fora da empresa).
     *
     * @return Collection<int, OwnedDomain>
     */
    public function provenFor(DomainOwner $owner): Collection
    {
        return $owner->constrain(OwnedDomain::query())
            ->whereNotNull('verified_at')
            ->oldest('id')
            ->get()
            ->each(fn (OwnedDomain $owned) => $owned->setAttribute('origin', $this->origin($owned)));
    }

    /**
     * "no Radar de domínios", "no alerta “Loja virtual”, em Notícias"...: onde a posse do nome foi comprovada. Na
     * empresa, sem o nome do alerta: ele pode ser de um setor que quem lê não vê.
     */
    public function origin(OwnedDomain $owned): string
    {
        if ($owned->source === 'news' && $owned->company_id !== null) {
            return 'em Notícias';
        }

        if ($owned->source === 'news') {
            $profile = DomainOwner::account($owned->user_id)->copies(MonitoredDomain::query()->ofAlerts())->where('domain', $owned->domain)
                ->with('profile')->oldest('id')->first()?->profile;

            return $profile ? 'no alerta “'.$profile->displayName().'”, em Notícias' : 'em Notícias';
        }

        return match ($owned->source) {
            'radar' => 'no Radar de domínios',
            'tests' => 'nos testes de segurança',
            'monitor' => 'no monitor de sites',
            'company' => 'em Domínios da empresa',
            default => 'no webhook',
        };
    }

    // ---- comprovar e conferir ---------------------------------------------------

    /**
     * Procura a prova: a que a conta já tem (sem consulta) ou o TXT no nome e nos de cima, até o
     * principal. Devolve o registro que comprova o nome, ou nulo.
     *
     * @param  list<?string>  $extra  tokens que a tela do serviço mostrou (ver claim())
     */
    public function verify(DomainOwner $owner, string $name, string $source, array $extra = []): ?OwnedDomain
    {
        return $this->proof($owner, $name) ?? $this->check($owner, $name, $source, $extra)[0];
    }

    /**
     * A conferência ao vivo, sempre no DNS: o nome e os de cima, até o principal, parando no primeiro
     * que tem o TXT. Grava o que viu em cada registro conferido. Devolve o registro que comprova o nome
     * (ou nulo) e o resultado: verdadeiro, achou; falso, não está; nulo, algum DNS não respondeu.
     *
     * @param  list<?string>  $extra
     * @return array{0: ?OwnedDomain, 1: ?bool}
     */
    public function check(DomainOwner $owner, string $name, string $source, array $extra = []): array
    {
        // Na empresa, só o token da empresa: o que a tela de um serviço mostrou não prova nada a mais. Na conta, o que a
        // tela mostrou vale como antes (vem de uma linha que o servidor gerou), menos o token de uma empresa e o token de
        // outra conta: achado, o prove() o gravaria no proof_token, e dali ele provaria para a conta todo nome com aquele
        // TXT, isto é, todos os domínios da outra conta (X1 do Red Team, DESENHO-5.3 §2.3).
        $tokens = $owner->isCompany() ? $this->tokens($owner) : [...$this->tokens($owner), ...array_filter(
            self::clean($extra),
            fn (string $token) => ! $this->registry->heldByAnother($token, $owner->userId),
        )];
        $unknown = false;

        foreach (self::selfAndParents($name) as $candidate) {
            $owned = $this->owned($owner, $candidate);

            // Na empresa, o nome sem linha própria abaixo de um domínio dela é provado pelo de cima (o próximo da volta).
            if ($owned === null && $owner->isCompany() && $this->covering($owner, $candidate) !== null) {
                continue;
            }

            $found = $this->find($candidate, $tokens);

            if (is_string($found)) {
                // O domínio de cima achado pela busca nasce sem setor (serve a todos, P1): senão o primeiro item a
                // comprovar um subdomínio levaria o domínio principal da empresa para o setor dele (Z3 do Red Team da E3a2).
                $owned ??= $this->claim($candidate === $name ? $owner : $owner->withoutSector(), $candidate, $source);

                // O TXT da empresa num nome de um setor que o item não usa: a prova vale para aquela linha, não cobre o item.
                if (! $owner->fits($owned->sector_id)) {
                    $this->prove($owned, $found, $source);

                    continue;
                }

                return [$this->prove($owned, $found, $source), true];
            }

            $unknown = $unknown || $found === null;

            if ($owned?->isVerified()) {
                $this->unseen($owned, $found === null ? 'unknown' : 'missing');
            } elseif ($owned) {
                $owned->forceFill(['checked_at' => now(), 'check_status' => $found === null ? 'unknown' : 'missing'])->save();
            }
        }

        return [null, $unknown ? null : false];
    }

    /**
     * A reconferência de uma posse comprovada (a diária, do agendador): só o TXT do próprio nome. O
     * TXT sumido por 7 dias (ou o DNS mudo) derruba a posse. Verdadeiro, está; falso, não; nulo, não sei.
     */
    public function recheck(OwnedDomain $owned): ?bool
    {
        $found = $this->find($owned->domain, $this->tokens($this->ownerOfOwned($owned)));

        if (is_string($found)) {
            $this->prove($owned, $found, $owned->source);

            return true;
        }

        $this->unseen($owned, $found === null ? 'unknown' : 'missing');

        return $found === null ? null : false;
    }

    /** Uma prova achada por fora (a da stack de testes, pela regra dela): entra na conta, nunca na empresa. */
    public function record(int $userId, string $name, string $token, string $source): OwnedDomain
    {
        $owner = DomainOwner::account($userId);
        $owned = $this->claim($owner, $name, $source, $token);

        // O token achado tem de ser da conta (o claim recusa o de outro dono): senão a prova não entra.
        return in_array($token, $this->tokens($owner), true) ? $this->prove($owned, $token, $source) : $owned;
    }

    // ---- quem é o dono ------------------------------------------------------------

    /**
     * O dono da posse de uma cópia: a cópia da stack é sempre da conta (fora da empresa, P4 do 5.2); a de um alerta ou
     * Radar da empresa, da empresa (com o setor do item); a do webhook, a do canal (webhookOwner); o resto, da conta.
     * `$root` é a raiz que ainda não está ligada à cópia (o Radar novo, gravado depois do domínio).
     */
    public function ownerOf(MonitoredDomain $row, ?Model $root = null): DomainOwner
    {
        if ($row->pentestDomain()->exists()) {
            return DomainOwner::account($row->user_id);
        }

        $root ??= $row->profile ?? $row->lookalikeWatch;

        if ($root === null && ($webhook = $this->webhookOwner($row)) !== null) {
            return $webhook;
        }

        return DomainOwner::of($root?->getAttribute('company_id'), $row->user_id, $root?->getAttribute('sector_id'));
    }

    /**
     * O dono da posse da cópia de um webhook: o do canal da empresa é a empresa com o setor do canal (E5a; nos gerais,
     * só domínio sem setor); o de uma conta de empresa sem canal da empresa (de antes da E5a), a empresa com qualquer
     * setor (o domínio foi escolhido entre os que a conta vê). Nulo se a cópia não é de webhook ou o webhook é pessoal.
     */
    private function webhookOwner(MonitoredDomain $row): ?DomainOwner
    {
        $channel = DB::table('notification_channels')->where('domain_id', $row->id)->first(['company_id', 'sector_id']);

        if ($channel === null) {
            return null;
        }

        if ($channel->company_id !== null) {
            return DomainOwner::company((int) $channel->company_id, $row->user_id, $channel->sector_id === null ? null : (int) $channel->sector_id);
        }

        $company = DB::table('company_user')->where('user_id', $row->user_id)->value('company_id');

        return $company === null ? null : DomainOwner::anySectorOf((int) $company, $row->user_id);
    }

    /**
     * A prova mais próxima do host entre os domínios que a conta vê (o webhook de conta de empresa, P2 do 5.3): o
     * domínio de um setor que ela não vê não serve, e o webhook não cadastra domínio da empresa.
     */
    public function visibleProof(User $user, string $host): ?OwnedDomain
    {
        return OwnedDomain::query()
            ->visibleTo($user)
            ->whereNotNull('verified_at')
            ->whereIn('domain', self::selfAndParents($host))
            ->get()
            ->sortByDesc(fn (OwnedDomain $owned) => strlen($owned->domain))
            ->first();
    }

    /** O dono de uma linha de posse. */
    public function ownerOfOwned(OwnedDomain $owned): DomainOwner
    {
        return DomainOwner::of($owned->company_id, $owned->user_id, $owned->sector_id);
    }

    /** O dono de um item novo pelo carimbo (Visibility::stampFor): a empresa, se a conta é de empresa. */
    public static function ownerOfStamp(array $stamp): DomainOwner
    {
        return DomainOwner::of($stamp['company_id'], $stamp['user_id'], $stamp['sector_id']);
    }

    // ---- o setor (P1 do 5.3) --------------------------------------------------------

    /**
     * O alerta ou o Radar da empresa pode ir para o setor? A linha de posse do domínio dele tem de servir ao setor novo
     * (sem setor ou o mesmo). O domínio da empresa (OwnedDomain) pode ir se nenhum item de outro setor depender dele.
     */
    public function ensureFitsSector(Model $item, ?int $sectorId): void
    {
        if ($item->getAttribute('company_id') === null) {
            return;
        }

        if ($item instanceof OwnedDomain) {
            if (($n = $this->blockingItems($item, $sectorId)) > 0) {
                throw new ActionRefused(($n === 1 ? '1 alerta ou Radar de outro setor usa' : "{$n} alertas ou Radares de outros setores usam")
                    .' este domínio. Mova-os antes, ou deixe o domínio sem setor.');
            }

            return;
        }

        $owner = DomainOwner::company((int) $item->getAttribute('company_id'), (int) $item->getAttribute('user_id'), $sectorId);
        $before = DomainOwner::company((int) $item->getAttribute('company_id'), (int) $item->getAttribute('user_id'), $item->getAttribute('sector_id'));

        foreach ($this->namesOf($item) as $name) {
            $owned = $this->owned($owner, $name);

            // O nome do item num setor que o destino não usa, ou a prova de hoje (a do nome ou a de um domínio de cima)
            // que deixaria de servir: o item perderia a posse calado (Z2 do Red Team da E3a2).
            if (($owned !== null && ! $owner->fits($owned->sector_id)) || ($this->proof($before, $name) !== null && $this->proof($owner, $name) === null)) {
                throw new ActionRefused('O domínio deste item está cadastrado na empresa num setor que o destino não usa. Peça à admin para deixar o domínio sem setor, ou escolha outro setor.');
            }
        }
    }

    /**
     * Quantos alertas e Radares da empresa, com o nome ou um subdomínio dele, deixariam de servir se o domínio fosse para
     * o setor (sem setor serve a todos). A tela de domínios mostra antes de trocar.
     */
    public function blockingItems(OwnedDomain $owned, ?int $sectorId): int
    {
        if ($sectorId === null || $owned->company_id === null) {
            return 0;
        }

        $names = fn (Builder $q) => $q->where('domain', $owned->domain)->orWhere('domain', 'like', '%.'.$owned->domain);
        $other = fn (Builder $q) => $q->where('company_id', $owned->company_id)->where(fn (Builder $q) => $q->whereNull('sector_id')->orWhere('sector_id', '!=', $sectorId));

        return $other(AlertProfile::query())->whereHas('domains', $names)->count()
            + $other(LookalikeWatch::query())->whereHas('domain', $names)->count();
    }

    /** Depois de trocar o setor: a posse de cada cópia do item é recalculada (pode deixar de servir, ou passar a servir). */
    public function refreshSector(Model $item): void
    {
        if ($item instanceof OwnedDomain) {
            $this->sync($this->ownerOfOwned($item), $item->domain);

            return;
        }

        foreach ($this->copiesOf($item) as $row) {
            $this->apply($row, $item);
        }
    }

    /** @return list<string> */
    private function namesOf(Model $item): array
    {
        return $this->copiesOf($item)->pluck('domain')->unique()->values()->all();
    }

    /** @return Collection<int, MonitoredDomain> */
    private function copiesOf(Model $item): Collection
    {
        return match (true) {
            $item instanceof AlertProfile => $item->domains()->get(),
            $item instanceof LookalikeWatch => collect([$item->domain])->filter(),
            default => collect(),
        };
    }

    // ---- as linhas de Notícias e do Radar ---------------------------------------

    /**
     * "Verificar" de um domínio de Notícias ou do Radar (o agendador e os testes): a prova da conta ou o
     * TXT no DNS, com o token que a linha mostra. Devolve se o domínio ficou comprovado.
     */
    public function verifyRow(MonitoredDomain $row, string $source): bool
    {
        $owner = $this->ownerOf($row);
        $this->claim($owner, $row->domain, $source, $row->verify_token);
        $proof = $this->verify($owner, $row->domain, $source, [$row->verify_token]);
        // A prova nova já chegou à linha (sync()); a que a conta já tinha chega agora.
        $this->apply($row->refresh());

        return $proof !== null;
    }

    /**
     * Botão "Verificar agora" de Notícias e do Radar: como verifyRow(), mas sem a prova recusa dizendo
     * o que falta. Com a posse comprovada e o TXT sumido, confere de novo agora.
     */
    public function confirmRow(MonitoredDomain $row, string $source): void
    {
        $proof = $this->proof($this->ownerOf($row), $row->domain);

        if ($proof?->unseen_since && $this->recheck($proof) !== true) {
            throw new ActionRefused("Ainda não encontramos o TXT secmon-verify={$proof->proof_token} em {$proof->domain}. Registros novos podem levar alguns minutos para propagar.");
        }

        if (! $proof && ! $this->verifyRow($row, $source)) {
            $this->refuse($row->domain, $row->txtValue(), parents: true);
        }

        $this->apply($row);
    }

    /**
     * Leva a posse do dono às linhas de Notícias, do Radar e do webhook com o nome ou um subdomínio dele (a cópia da
     * stack fica de fora). Cada linha resolve o dono dela de novo no apply().
     */
    public function sync(DomainOwner $owner, string $name): void
    {
        $owner->copies(MonitoredDomain::query())
            ->whereDoesntHave('pentestDomain')
            ->get()
            ->filter(fn (MonitoredDomain $row) => $row->domain === $name || str_ends_with($row->domain, '.'.$name))
            ->each(fn (MonitoredDomain $row) => $this->apply($row));
    }

    /**
     * A posse da conta numa linha de Notícias ou do Radar: `verified_at` (os leitores de sempre, como o
     * RadarEnrollment e a descoberta, olham ele), a hora da última conferência e o token que a tela
     * mostra. A linha que ficou comprovada agora, com aceite, já enfileira a descoberta.
     */
    public function apply(MonitoredDomain $row, ?Model $root = null): void
    {
        if ($row->pentestDomain()->exists()) {
            return;
        }

        $owner = $this->ownerOf($row, $root);
        $proof = $this->proof($owner, $row->domain);
        $own = $this->owned($owner, $row->domain);
        $was = $row->isVerified();

        $row->forceFill([
            'verified_at' => $proof ? ($row->verified_at ?? $proof->verified_at) : null,
            'verify_checked_at' => $own?->checked_at ?? $proof?->checked_at ?? $row->verify_checked_at,
            'verify_token' => $proof?->proof_token ?? $row->verify_token,
        ])->save();

        if (! $was && $row->isVerified() && $row->canDiscover() && Settings::bool('alerts.enabled') && Settings::bool('alerts.discovery_enabled')) {
            // Depois do commit: a entrevista grava o domínio numa transação.
            DiscoverDomainStack::dispatch($row->id)->afterCommit();
        }
    }

    /**
     * O que o quadro "Comprove que o domínio é seu" (<x-domain-ownership>) mostra para um nome da conta:
     * o TXT a criar e onde (no principal, que cobre os subdomínios, ou no próprio nome), ou a prova.
     * `$pending` força o quadro do TXT (o monitor de sites, que confere ao vivo em cada varredura).
     *
     * @return array<string, mixed>
     */
    public function screen(DomainOwner $owner, string $name, string $token, bool $pending = false): array
    {
        $proof = $pending ? null : $this->proof($owner, $name);
        $zone = RegistrableDomain::of($name) ?? $name;

        return [
            'name' => $name,
            'txt' => self::PREFIX.($proof?->proof_token ?? $token),
            'zone' => $zone,
            // O nome do registro no painel do DNS da zona principal: "@" nela, o rótulo no subdomínio.
            'label' => $name === $zone ? '@' : substr($name, 0, -strlen($zone) - 1),
            'state' => match (true) {
                $proof === null => 'pending',
                $proof->unseen_since !== null => 'unseen',
                default => 'proven',
            },
            'proofName' => $proof?->domain,
            'verifiedAt' => $proof?->verified_at,
            'checkedAt' => $this->owned($owner, $name)?->checked_at,
            'unseenSince' => $proof?->unseen_since,
            'fallsAt' => $proof?->fallsAt(),
            'legacy' => (bool) $proof?->legacy,
            'origin' => $proof ? $this->origin($proof) : null,
        ];
    }

    /** O quadro de uma linha de Notícias ou do Radar. */
    public function screenFor(MonitoredDomain $row): array
    {
        return $this->screen($this->ownerOf($row), $row->domain, $row->verify_token);
    }

    // ---- a cópia da stack de testes (a regra dela) ------------------------------

    /**
     * A cópia da stack de testes, pela regra dela: só o token da cópia, no próprio nome. Achou, a cópia
     * fica comprovada e a prova entra na conta (os outros serviços não pedem de novo).
     */
    public function verifyCopy(MonitoredDomain $copy): bool
    {
        if ($copy->isVerified()) {
            return true;
        }

        $found = $this->find($copy->domain, [$copy->verify_token]);

        DB::transaction(function () use ($copy, $found) {
            $copy->forceFill(['verify_checked_at' => now(), 'verified_at' => is_string($found) ? now() : null])->save();

            if (is_string($found)) {
                $this->record($copy->user_id, $copy->domain, $found, 'tests');
            }
        });

        return is_string($found);
    }

    /** Botão "Verificar agora" da cópia: como verifyCopy(), mas sem o TXT recusa dizendo o que falta. */
    public function confirmCopy(MonitoredDomain $copy): void
    {
        if (! $this->verifyCopy($copy)) {
            $this->refuse($copy->domain, $copy->txtValue(), parents: false);
        }
    }

    /**
     * A reconferência da cópia ("Conferir de novo" e o portão das execuções): o TXT da cópia continua no
     * próprio nome? Verdadeiro, está; falso, sumiu; nulo, o DNS não respondeu. Não grava nada.
     */
    public function recheckCopy(MonitoredDomain $copy): ?bool
    {
        $found = $this->find($copy->domain, [$copy->verify_token]);

        return $found === null ? null : $found !== false;
    }

    /**
     * A cópia ainda sem prova, de um nome que a conta já comprovou noutro serviço: passa a conferir o
     * token daquela prova (o que está no DNS). Só a cópia sem prova: a comprovada nunca troca de token.
     */
    public function adoptCopy(MonitoredDomain $copy): bool
    {
        $proof = $copy->isVerified() ? null : $this->exactProof(DomainOwner::account($copy->user_id), $copy->domain);

        if (! $proof?->proof_token || $proof->proof_token === $copy->verify_token) {
            return false;
        }

        $copy->forceFill(['verify_token' => $proof->proof_token])->save();

        return true;
    }

    // ---- por dentro --------------------------------------------------------------

    /** O registro do próprio nome (comprovado ou não). */
    private function owned(DomainOwner $owner, string $name): ?OwnedDomain
    {
        return $owner->constrain(OwnedDomain::query())->where('domain', $name)->first();
    }

    /** A linha nova do nome no dono; dois pedidos juntos ficam com a mesma (os únicos parciais da E3a0). */
    private function create(DomainOwner $owner, string $name, string $source): OwnedDomain
    {
        try {
            return DB::transaction(function () use ($owner, $name, $source) {
                if ($owner->isCompany()) {
                    $this->ensureRoom($owner->companyId);
                }

                $owned = OwnedDomain::query()->forceCreate($owner->columns() + ['domain' => $name, 'source' => $source]);

                if ($owner->isCompany()) {
                    // O aviso de 80%/100% do limite sai depois do commit (o cadastro pode ainda voltar atrás).
                    DB::afterCommit(fn () => $this->notices->domains($owner->companyId));
                }

                return $owned;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->owned($owner, $name) ?? throw new LogicException("Posse de {$name} sumiu no meio do cadastro.");
        }
    }

    /**
     * O limite de domínios da empresa (R5: 15, somando os setores; pendentes também contam, senão a espera do TXT não
     * teria teto). A empresa fica travada até o fim da transação de quem cadastra: dois cadastros juntos não passam.
     */
    private function ensureRoom(int $companyId): void
    {
        $company = Company::query()->lockForUpdate()->findOrFail($companyId);
        $limit = app(Companies::class)->limit($company, 'domains');

        if (OwnedDomain::query()->where('company_id', $companyId)->count() >= $limit) {
            throw new ActionRefused("A empresa chegou ao limite de {$limit} domínios. Remova um em Domínios da empresa para cadastrar outro.");
        }
    }

    /** Achou o TXT: a posse fica comprovada (ou em dia) e as linhas dos serviços ficam sabendo. */
    private function prove(OwnedDomain $owned, string $token, string $source): OwnedDomain
    {
        $was = $owned->isVerified();

        $owned->forceFill([
            'verified_at' => $owned->verified_at ?? now(),
            'source' => $was ? $owned->source : $source,
            'proof_token' => $token,
            // Visto de novo: daqui em diante, a prova de antes segue a regra de todas.
            'legacy' => false,
            'checked_at' => now(),
            'check_status' => 'present',
            'unseen_since' => null,
            'lost_at' => null,
            'waiting_since' => null,
        ])->save();

        if (! $was) {
            $this->sync($this->ownerOfOwned($owned), $owned->domain);
        }

        return $owned;
    }

    /** O TXT da posse comprovada não apareceu: conta o tempo, e passado o prazo a posse cai. */
    private function unseen(OwnedDomain $owned, string $status): void
    {
        // As duas transições (o 1º dia sem o TXT e a queda) são updates condicionais: duas reconferências ao mesmo tempo
        // (o agendador e o "Verificar agora") não mandam o aviso duas vezes; só quem fez a transição avisa.
        $now = now();
        $first = OwnedDomain::query()->whereKey($owned->id)->whereNull('unseen_since')->update(['unseen_since' => $now]) === 1;
        $owned->forceFill(['checked_at' => $now, 'check_status' => $status])->save();
        $owned->refresh();

        if ($owned->fallsAt()?->lte(now())) {
            $fell = OwnedDomain::query()->whereKey($owned->id)->whereNotNull('verified_at')
                ->update(['verified_at' => null, 'lost_at' => $now, 'waiting_since' => $now]) === 1;
            $owned->refresh();

            if (! $fell) {
                return;
            }

            $this->sync($this->ownerOfOwned($owned), $owned->domain);

            // O ponto único da queda: na empresa, quem configura o domínio fica sabendo (E3d).
            if ($owned->company_id !== null) {
                $this->notices->lost($owned);
            }

            return;
        }

        // 1º dia sem o TXT de um domínio da empresa: o aviso de que a posse cai em 7 dias (P6). A prova de antes da
        // unificação (`legacy`) não cai, e a conta pessoal não recebe e-mail.
        if ($first && $owned->company_id !== null && $owned->fallsAt() !== null) {
            $this->notices->unseen($owned);
        }
    }

    /** Sem a prova: num apelido (CNAME) o TXT nunca vai aparecer, e a recusa diz por quê. */
    private function refuse(string $name, string $txt, bool $parents): never
    {
        if ($target = $this->dns->cname($name)[0] ?? null) {
            $zone = RegistrableDomain::of($name);

            throw new ActionRefused("{$name} é um apelido (CNAME) de {$target}. Um apelido não tem TXT próprio: o TXT que aparece nele é o do destino, e não prova que o nome é seu. "
                .($parents && $zone && $zone !== $name
                    ? "Crie o TXT em {$zone}: ele vale também para os subdomínios."
                    : 'Cadastre o domínio principal, onde o TXT pode ficar.'));
        }

        throw new ActionRefused("Ainda não encontramos o TXT {$txt} em {$name}. Registros novos podem levar alguns minutos para propagar.");
    }

    /**
     * O nome e os de cima, até o principal: "a.loja.empresa.com.br" → ele, "loja.empresa.com.br" e
     * "empresa.com.br". Nunca o sufixo público (com.br).
     *
     * @return list<string>
     */
    public static function selfAndParents(string $name): array
    {
        $zone = RegistrableDomain::of($name);
        $names = [$name];

        while ($zone !== null && $name !== $zone && str_ends_with($name, '.'.$zone)) {
            $name = substr($name, strpos($name, '.') + 1);
            $names[] = $name;
        }

        return $names;
    }

    /** @return list<string> */
    private static function clean(array $tokens): array
    {
        return array_values(array_unique(array_filter($tokens, fn ($token) => is_string($token) && $token !== '')));
    }
}
