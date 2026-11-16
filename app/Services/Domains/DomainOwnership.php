<?php

namespace App\Services\Domains;

use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Support\RegistrableDomain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A posse de domínio, uma só para o dono (`owned_domains`): usada por Notícias, pelo Radar de
 * domínios, pelo monitor de sites e pelo webhook. O dono (DomainOwner) é a conta: instalação
 * própria, de um dono só, então não há posse de outra conta para proteger.
 *
 * Esta é uma instalação self-hosted, de um dono só: não existe a prova de posse por registro TXT no
 * DNS que o SaaS original pedia. Todo domínio cadastrado em qualquer serviço nasce já comprovado
 * (`verified_at` logo na criação, em OwnedDomain e em MonitoredDomain): quem instalou o Security
 * Monitor é o único dono de todas as contas, então não existe risco de vigiar ou testar o domínio de
 * outra pessoa pelo próprio painel. A classe continua existindo porque é ela que guarda, num lugar
 * só, quem é o dono de cada domínio e como um nome já cadastrado num serviço é reaproveitado sem novo
 * cadastro no Radar, no webhook etc. (claim/proof/apply).
 *
 * A stack de testes de segurança (Red Team e Blue Team) tem uma regra própria e mais forte: antes de
 * autorizar um teste ativo, a pessoa confirma explicitamente, numa caixa de diálogo, que o domínio é
 * dela (ver PentestWizard e PentestDomain::responsibilityConfirmed()) — não há checagem técnica atrás
 * disso, só a confirmação, e ela fica na auditoria.
 */
class DomainOwnership
{
    /** O nome no dono, já comprovado (find-or-create): nenhum serviço pede prova de posse. */
    public function claim(DomainOwner $owner, string $name, string $source): OwnedDomain
    {
        return $this->owned($owner, $name) ?? $this->create($owner, $name, $source);
    }

    /** O registro comprovado que vale para o nome: o dele ou o do domínio de cima mais próximo. */
    public function proof(DomainOwner $owner, string $name): ?OwnedDomain
    {
        return $owner->constrainFitting(OwnedDomain::query())
            ->whereNotNull('verified_at')
            ->whereIn('domain', self::selfAndParents($name))
            ->get()
            ->sortByDesc(fn (OwnedDomain $owned) => strlen($owned->domain))
            ->first();
    }

    /**
     * A prova do próprio nome, em dia (a de um domínio de cima não vale, e a que a reconferência não
     * achou mais não entra: a stack pararia na 1ª conferência). A stack de testes usa esta.
     */
    public function exactProof(DomainOwner $owner, string $name): ?OwnedDomain
    {
        $owned = $this->owned($owner, $name);

        return $owned?->isVerified() && $owned->unseen_since === null ? $owned : null;
    }

    /**
     * Os nomes comprovados que a conta vê, do mais antigo ao mais novo, cada um com `origin` (de onde
     * veio a prova): o passo 1 do Radar e dos testes oferece reaproveitar.
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
     * Os nomes comprovados de um dono, do mais antigo ao mais novo, com `origin`.
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

    /** "no Radar de domínios", "no alerta “Loja virtual”, em Notícias"...: onde o nome foi cadastrado primeiro. */
    public function origin(OwnedDomain $owned): string
    {
        if ($owned->source === 'news') {
            $profile = DomainOwner::account($owned->user_id)->copies(MonitoredDomain::query()->ofAlerts())->where('domain', $owned->domain)
                ->with('profile')->oldest('id')->first()?->profile;

            return $profile ? 'no alerta “'.$profile->displayName().'”, em Notícias' : 'em Notícias';
        }

        return match ($owned->source) {
            'radar' => 'no Radar de domínios',
            'tests' => 'nos testes de segurança',
            'monitor' => 'no monitor de sites',
            'keys' => 'em Chaves expostas',
            default => 'no webhook',
        };
    }

    // ---- quem é o dono ------------------------------------------------------------

    /** O dono da posse de uma cópia: instalação de um dono só, então é sempre a conta. */
    public function ownerOf(MonitoredDomain $row, ?Model $root = null): DomainOwner
    {
        return DomainOwner::of($row->user_id);
    }

    /** A prova mais próxima do host entre os domínios que a conta vê (o webhook). */
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
        return DomainOwner::of($owned->user_id);
    }

    /** O dono de um item novo pelo carimbo (Visibility::stampFor). */
    public static function ownerOfStamp(array $stamp): DomainOwner
    {
        return DomainOwner::of($stamp['user_id']);
    }

    // ---- as linhas de Notícias e do Radar ---------------------------------------

    /**
     * A posse da conta numa linha de Notícias ou do Radar: garante `verified_at` (os leitores de
     * sempre, como o RadarEnrollment e a descoberta, olham ele). Instalação de um dono só: a linha já
     * nasce com `verified_at` (MonitoredDomain::booted()); este método existe para as linhas que vierem
     * a existir sem passar por lá, e para a tela mostrar a mesma data da prova de uma linha-irmã, quando
     * houver (a prova mais antiga vence a hora de agora, que é só o valor de nascença).
     */
    public function apply(MonitoredDomain $row, ?Model $root = null): void
    {
        if ($row->pentestDomain()->exists()) {
            return;
        }

        $owner = $this->ownerOf($row, $root);
        $proof = $this->proof($owner, $row->domain);

        $row->forceFill([
            'verified_at' => $proof?->verified_at ?? $row->verified_at ?? now(),
        ])->save();
    }

    // ---- por dentro --------------------------------------------------------------

    /** O registro do próprio nome (comprovado ou não — hoje, sempre comprovado). */
    private function owned(DomainOwner $owner, string $name): ?OwnedDomain
    {
        return $owner->constrain(OwnedDomain::query())->where('domain', $name)->first();
    }

    /** A linha nova do nome no dono, já comprovada; dois pedidos juntos ficam com a mesma. */
    private function create(DomainOwner $owner, string $name, string $source): OwnedDomain
    {
        try {
            return DB::transaction(function () use ($owner, $name, $source) {
                return OwnedDomain::query()->forceCreate($owner->columns() + ['domain' => $name, 'source' => $source]);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->owned($owner, $name) ?? throw new LogicException("Posse de {$name} sumiu no meio do cadastro.");
        }
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
}
