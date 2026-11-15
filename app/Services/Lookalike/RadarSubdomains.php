<?php

namespace App\Services\Lookalike;

use App\Models\Company;
use App\Models\LookalikeWatch;
use App\Models\OwnedDomain;
use App\Models\OwnedSubdomain;
use App\Models\SubdomainAlert;
use App\Services\Company\Companies;
use App\Services\Company\CompanyDomainNotices;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\SubdomainCheck;
use Illuminate\Support\Collection;
use Throwable;

/**
 * O Radar de empresa vigia os subdomínios ativos do domínio dele (E4a, DESENHO-5.3 §5.2, P7): em cada varredura completa,
 * o DNS de cada um (SubdomainCheck: só DNS, nada vai ao servidor do cliente) e dois avisos, só na passagem de um estado
 * para o outro (o mesmo estado no dia seguinte não avisa de novo):
 *
 * - `takeover` (Crítico): o CNAME sai do domínio e o destino não resolve. Quem criar o destino responde pelo subdomínio;
 * - `outside` (Alto): o subdomínio estava na zona da empresa e passou a apontar para fora (CNAME ou DNS de terceiro).
 *
 * O DNS que não respondeu não conta como mudança: o estado fica o de antes. Os IPs dos subdomínios não entram no
 * BrandContext (o `same_ip` do RiskScorer): CDN e hospedagem compartilhada branqueariam golpes na mesma borda (§5.2.2).
 */
class RadarSubdomains
{
    public function __construct(
        private SubdomainCheck $check,
        private SubdomainAlertRouter $router,
        private Companies $companies,
        private CompanyDomainNotices $notices,
    ) {}

    /** O Radar vigia subdomínios? A chave do ambiente, Radar de empresa ativa. */
    public function enabled(LookalikeWatch $watch): bool
    {
        return (bool) config('secmon.lookalikes.subdomains_enabled') && $watch->company_id !== null
            && ($company = Company::query()->find($watch->company_id)) !== null && $this->companies->isActive($company);
    }

    /**
     * O domínio da empresa com o mesmo nome do Radar (a posse dele), se houver, e só se servir ao setor do Radar (P1 do
     * 5.3: sem setor ou o mesmo; o Radar sem setor, só o domínio sem setor). A regra já impede outro caso; aqui fica explícita.
     */
    public function ownedDomain(LookalikeWatch $watch): ?OwnedDomain
    {
        $name = $watch->domain?->domain;

        return $name === null ? null : DomainOwner::company((int) $watch->company_id, (int) $watch->user_id, $watch->sector_id)
            ->constrainFitting(OwnedDomain::query())->where('domain', $name)->first();
    }

    /** @return Collection<int, OwnedSubdomain> os subdomínios ativos que o Radar vigia, por nome */
    public function watched(LookalikeWatch $watch): Collection
    {
        $owned = $this->enabled($watch) ? $this->ownedDomain($watch) : null;

        return $owned === null ? new Collection
            : OwnedSubdomain::query()->where('owned_domain_id', $owned->id)->where('status', 'active')->orderBy('name')->get()->each->setRelation('ownedDomain', $owned);
    }

    /**
     * As sementes de imitação deste Radar (E4b, §5.3): os subdomínios vigiados que lembram a marca (VariantGenerator::isSeed),
     * dentro do teto da empresa (`radar_seeds`, 300; P8: conta só subdomínios, de todos os Radares ativos dela). Passou do
     * teto, entram os mais antigos (`activated_at`). Avisa a empresa em 80% e 100% (E3d).
     *
     * @return array{seeds: Collection<int, OwnedSubdomain>, used: int, limit: int, left_out: int}
     */
    public function seeds(LookalikeWatch $watch): array
    {
        $empty = ['seeds' => new Collection, 'used' => 0, 'limit' => 0, 'left_out' => 0];
        $own = $this->enabled($watch) ? $this->ownedDomain($watch) : null;

        if ($own === null) {
            return $empty;
        }

        $company = Company::query()->findOrFail($watch->company_id);
        $limit = $this->companies->limit($company, 'radar_seeds');
        $owned = LookalikeWatch::query()->where('company_id', $company->id)->where('status', 'active')->get()
            ->map(fn (LookalikeWatch $w) => $w->is($watch) ? $own : $this->ownedDomain($w))->filter()->pluck('id')->unique()->values();
        $all = OwnedSubdomain::query()->whereIn('owned_domain_id', $owned)->where('status', 'active')
            ->orderBy('activated_at')->orderBy('id')->get()
            ->filter(fn (OwnedSubdomain $sub) => VariantGenerator::isSeed($sub->name))->values();
        $inside = $all->take($limit)->where('owned_domain_id', $own->id)->values();
        $this->notices->limit($company, 'radar_seeds', $all->count(), $limit);

        return [
            'seeds' => $inside->each->setRelation('ownedDomain', $own),
            'used' => $all->count(),
            'limit' => $limit,
            'left_out' => $all->where('owned_domain_id', $own->id)->count() - $inside->count(),
        ];
    }

    /**
     * Relê o DNS dos subdomínios vigiados e avisa o que mudou. Devolve as linhas do log da varredura.
     *
     * @return list<string>
     */
    public function scan(LookalikeWatch $watch): array
    {
        $subdomains = $this->watched($watch);

        if ($subdomains->isEmpty()) {
            return [];
        }

        $mute = 0;
        $alerts = 0;

        foreach ($subdomains as $sub) {
            try {
                $result = $this->check->check($sub->ownedDomain->domain, $sub->name);
            } catch (Throwable $e) {
                report($e);
                $mute++;

                continue;
            }

            $before = $sub->facts ?? [];

            // A zona que não deu para conferir (o NS de um nível acima do nome, ou de um salto do CNAME, não respondeu)
            // vira `outside`; para quem estava dentro, é DNS mudo, não mudança: o estado fica (M1 do Red Team da E4a).
            if ($result['reason'] === 'dns_mudo' || (($result['facts']['zone'] ?? null) === 'desconhecido' && ! ($before['outside'] ?? false))) {
                $mute++;

                continue;
            }

            $sub->forceFill([
                'facts' => $result['facts'],
                'reason' => $result['reason'],
                'last_seen_at' => $result['resolves'] ? now() : $sub->last_seen_at,
                'resolved_at' => $result['resolves'] ? ($sub->resolved_at ?? now()) : $sub->resolved_at,
            ])->save();

            $kind = match (true) {
                self::takeover($result['facts']) && ! self::takeover($before) => 'takeover',
                $result['outside'] && ! ($before['outside'] ?? false) && ! self::takeover($result['facts']) => 'outside',
                default => null,
            };

            if ($kind !== null) {
                $alert = SubdomainAlert::query()->create([
                    'owned_subdomain_id' => $sub->id,
                    'watch_id' => $watch->id,
                    'kind' => $kind,
                    'risk' => $kind === 'takeover' ? 'critical' : 'high',
                    'facts' => $result['facts'],
                ]);
                $this->router->route($alert->setRelation('watch', $watch)->setRelation('subdomain', $sub));
                $alerts++;
            }
        }

        $count = $subdomains->count();

        return array_values(array_filter([
            "Subdomínios vigiados: {$count} ".($count === 1 ? 'conferido' : 'conferidos').' no DNS (só DNS; nada é aberto nos servidores da empresa).',
            $mute ? "{$mute} sem resposta do DNS agora: ficam como estavam até a próxima varredura." : null,
            $alerts ? "{$alerts} ".($alerts === 1 ? 'aviso' : 'avisos').' de subdomínio (tomada ou mudança para fora).' : null,
        ]));
    }

    /** O CNAME sai do domínio e o fim da cadeia não resolve (um laço ou cadeia longa demais não é tomada). */
    public static function takeover(array $facts): bool
    {
        return ($facts['cname_out'] ?? false) && ($facts['cname_stop'] ?? null) === null && ($facts['ips'] ?? null) === [];
    }
}
