<?php

namespace App\Services\Domains;

use App\Models\Company;
use App\Models\OwnedDomain;
use App\Models\OwnedSubdomain;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Company\Companies;
use App\Services\Company\CompanyAudit;
use App\Services\Company\CompanyDomainNotices;
use App\Services\Lookalike\CertificateSearch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A descoberta passiva dos subdomínios de um domínio comprovado da empresa (E3c, DESENHO-5.3 §3.3, R8). Só o que é
 * público: os certificados (crt.sh, um serviço de terceiro que recebe o nome do domínio) e o DNS do Security Monitor (SubdomainCheck,
 * as regras do recon passivo). Nada vai ao servidor do cliente.
 *
 * A regra B2 (da #75 e da P9c): **só entra ativo o que resolve e está na zona da empresa**, até o teto de 20 ativos por
 * domínio. O resto é sugestão, com o motivo, para quem configura o domínio escolher: o que aponta para terceiro (CNAME
 * para fora, delegação de fora, zona filha de fora ou sem como saber) e o que passou do teto. O nome que não resolve ou
 * cujo DNS não respondeu não é gravado: o DNS mudo volta na próxima execução; o que não resolve só depois de
 * `skip_days` (a lista fica no cache), para os nomes mortos dos certificados não tomarem a vez dos novos todo dia.
 *
 * O que já existe em qualquer status não muda (o `ignored` não volta, a sugestão fica sugestão), menos o `gone`, que é
 * conferido de novo. Os ativos são conferidos todo dia: sem endereço por `gone_after_days`, saem e liberam a vaga; o
 * passivo que passou a apontar para fora vira sugestão (o manual fica, com a marca `outside`, P5).
 */
class SubdomainDiscovery
{
    public function __construct(
        private CertificateSearch $certificates,
        private SubdomainCheck $dns,
        private Companies $companies,
        private CompanyAudit $audit,
        private CompanyDomainNotices $notices,
        private DnsResolver $resolver,
    ) {}

    /** O domínio pode passar pela descoberta agora? A empresa ativa, o domínio dela, comprovado e com o TXT em dia. */
    public function eligible(OwnedDomain $domain): bool
    {
        $company = $domain->company_id ? Company::query()->find($domain->company_id) : null;

        return (bool) config('secmon.subdomains.discovery_enabled') && $company !== null && $this->companies->isActive($company)
            && $domain->verified_at !== null && $domain->unseen_since === null;
    }

    /** @return array{novos: int, ativos: int, sugestoes: int, saíram: int, erro: ?string}|null nulo: não passou (ver eligible) */
    public function run(OwnedDomain $domain): ?array
    {
        $domain = $domain->fresh();

        if ($domain === null || ! $this->eligible($domain)) {
            return null;
        }

        $started = microtime(true);
        $summary = ['novos' => 0, 'ativos' => 0, 'sugestoes' => 0, 'saíram' => 0, 'erro' => null];
        $found = $this->certificates->subdomains($domain->domain, (int) config('secmon.subdomains.max_ct_names'));
        $summary['erro'] = $found['error'];

        // 1. Os ativos de hoje, conferidos (antes dos novos: a vaga liberada vale já), dentro do mesmo orçamento de tempo:
        // os conferidos há mais tempo primeiro, e o que não couber fica para a próxima execução.
        foreach ($domain->subdomains()->where('status', 'active')->orderByRaw('last_seen_at asc nulls first')->orderBy('id')->get() as $sub) {
            if (microtime(true) - $started > (int) config('secmon.subdomains.budget_seconds')) {
                break;
            }

            $summary['saíram'] += $this->recheck($domain, $sub);
        }

        // 2. Os nomes novos dos certificados, até o teto da execução e do tempo.
        // A sugestão por zona que não deu para conferir (um NS que não respondeu) volta a ser avaliada depois de `skip_days`,
        // como os nomes mortos: senão um timeout de um dia a deixaria sugestão para sempre.
        $known = $domain->subdomains()->where('status', '!=', 'gone')->get()->reject(fn (OwnedSubdomain $sub) => $this->retryable($sub))->pluck('name')->flip();
        $skipKey = "subdominios:sem-ip:{$domain->id}";
        $dead = array_filter((array) Cache::get($skipKey, []), fn (int $at) => $at > now()->subDays((int) config('secmon.subdomains.skip_days'))->getTimestamp());
        $new = collect($found['hosts'])
            ->map(fn (string $name) => CompanySubdomains::normalize($name))
            ->filter(fn (?string $name) => $name !== null && $name !== $domain->domain && str_ends_with($name, '.'.$domain->domain) && ! $known->has($name) && ! isset($dead[$name]))
            ->unique()->take((int) config('secmon.subdomains.new_per_run'));

        $probes = [];

        foreach ($new as $name) {
            if (microtime(true) - $started > (int) config('secmon.subdomains.budget_seconds')) {
                break;
            }

            $check = $this->dns->check($domain->domain, $name);

            if ($check['resolves'] === false) {
                $dead[$name] = now()->getTimestamp();
            }

            // O nome que só resolve pelo curinga do nível (N1 do Red Team da E3c): sugestão, nunca ativo. O DNS mudo no
            // rótulo aleatório é "não sei": o nome fica para a próxima execução.
            if ($check['resolves'] === true && ! $check['outside']) {
                $wildcard = $this->wildcard($name, $check, $probes);

                if ($wildcard === null) {
                    continue;
                }

                $check['wildcard'] = $wildcard;
            }

            $outcome = $this->record($domain, $name, $check);
            $summary['novos'] += $outcome === null ? 0 : 1;
            $summary[$outcome === 'active' ? 'ativos' : 'sugestoes'] += $outcome === null ? 0 : 1;
        }

        // Os mais recentes ficam, até um teto (um domínio com milhares de nomes mortos não cresce o cache sem fim).
        arsort($dead);
        Cache::put($skipKey, array_slice($dead, 0, 5000, true), now()->addDays((int) config('secmon.subdomains.skip_days') + 1));
        $domain->forceFill(['subdomains_checked_at' => now(), 'subdomains_error' => $found['error'] ? mb_substr($found['error'], 0, 64) : null])->save();
        $company = Company::query()->findOrFail($domain->company_id);

        if ($summary['novos'] + $summary['saíram'] > 0) {
            $this->audit->record($company, null, 'subdomains.discovered', targetLabel: $domain->domain, data: array_filter([
                'ativos' => $summary['ativos'], 'sugestões' => $summary['sugestoes'], 'saíram' => $summary['saíram'],
            ]));
        }

        if ($summary['ativos'] + $summary['saíram'] > 0) {
            $active = $domain->subdomains()->where('status', 'active')->count();
            $this->notices->limit($company, 'subdomains', $active, $this->companies->limit($company, 'subdomains'), $domain);
        }

        return $summary;
    }

    /**
     * Um nome novo: ativo (resolve, na zona, cabe no teto), sugestão (fora da zona ou acima do teto) ou nada (não resolve,
     * DNS mudo, sugestões demais). Com a linha do domínio travada, como o cadastro à mão.
     *
     * @param  array{resolves: ?bool, outside: bool, reason: ?string, facts: array}  $check
     */
    private function record(OwnedDomain $domain, string $name, array $check): ?string
    {
        if ($check['resolves'] !== true) {
            return null;
        }

        return DB::transaction(function () use ($domain, $name, $check) {
            $locked = OwnedDomain::query()->lockForUpdate()->findOrFail($domain->id);
            $company = Company::query()->findOrFail($locked->company_id);
            $current = $locked->subdomains()->where('name', $name)->first();

            // Entre a lista e a trava, alguém cadastrou à mão (ou tirou): fica como está, menos o `gone` e a sugestão por zona
            // que não respondeu, já vencida.
            if ($current !== null && $current->status !== 'gone' && ! $this->retryable($current)) {
                return null;
            }

            $room = $locked->subdomains()->where('status', 'active')->count() < $this->companies->limit($company, 'subdomains');
            $wildcard = (bool) ($check['wildcard'] ?? false);
            $status = ! $check['outside'] && ! $wildcard && $room ? 'active' : 'suggested';

            if ($status === 'suggested' && $locked->subdomains()->where('status', 'suggested')->count() >= (int) config('secmon.subdomains.max_suggested')) {
                return null;
            }

            $sub = $current ?? new OwnedSubdomain(['owned_domain_id' => $locked->id, 'name' => $name, 'origin' => 'passive', 'first_seen_at' => now()]);
            $sub->forceFill([
                'status' => $status,
                'reason' => $check['outside'] ? $check['reason'] : ($wildcard ? 'curinga' : ($status === 'suggested' ? 'teto' : null)),
                'facts' => $check['facts'],
                'last_seen_at' => now(),
                'resolved_at' => now(),
                'activated_at' => $status === 'active' ? now() : null,
                'activated_by' => null,
            ])->save();

            return $status;
        });
    }

    /**
     * O nome resolve só pelo curinga do nível dele? Um rótulo aleatório no mesmo nível responde, e com os mesmos IPs.
     * Uma consulta por nível por execução. Nulo: o DNS não respondeu.
     *
     * @param  array<string, ?list<string>>  $probes
     */
    private function wildcard(string $name, array $check, array &$probes): ?bool
    {
        $parent = substr($name, strpos($name, '.') + 1);

        if (! array_key_exists($parent, $probes)) {
            $probes[$parent] = $this->resolver->ipsOrNull('secmon-curinga-'.bin2hex(random_bytes(6)).'.'.$parent);
        }

        $ips = $probes[$parent];

        if ($ips === null) {
            return null;
        }

        return $ips !== [] && array_diff((array) ($check['facts']['ips'] ?? []), $ips) === [];
    }

    /** A sugestão passiva por zona sem resposta, vista há mais de `skip_days`: avaliada de novo. */
    private function retryable(OwnedSubdomain $sub): bool
    {
        return $sub->status === 'suggested' && $sub->origin === 'passive' && $sub->reason === 'zona_desconhecida'
            && ($sub->last_seen_at ?? $sub->first_seen_at)?->lte(now()->subDays((int) config('secmon.subdomains.skip_days')));
    }

    /** O ativo de todo dia: segue no ar, saiu do ar (por dias: `gone`) ou o passivo passou a apontar para fora (sugestão). @return int 1 se saiu dos ativos */
    private function recheck(OwnedDomain $domain, OwnedSubdomain $sub): int
    {
        $check = $this->dns->check($domain->domain, $sub->name);

        // O DNS mudo, e a zona que não deu para conferir (um NS que não respondeu) para quem estava dentro: o estado fica
        // (N2 do Red Team da E3c), senão um timeout tiraria o ativo das vagas.
        if ($check['resolves'] === null || (($check['facts']['zone'] ?? null) === 'desconhecido' && ! $sub->isOutside())) {
            return 0;
        }

        if ($check['resolves'] === false) {
            $since = $sub->last_seen_at ?? $sub->activated_at ?? $sub->first_seen_at;

            if ($since !== null && $since->lte(now()->subDays((int) config('secmon.subdomains.gone_after_days')))) {
                $sub->forceFill(['status' => 'gone', 'reason' => 'sem_ip', 'activated_at' => null, 'activated_by' => null])->save();

                return 1;
            }

            return 0;
        }

        // O passivo que passou a apontar para fora não fica ativo sozinho (B2); o manual fica, com a marca (P5).
        if ($check['outside'] && $sub->origin === 'passive') {
            $sub->forceFill(['status' => 'suggested', 'reason' => $check['reason'], 'facts' => $check['facts'], 'last_seen_at' => now(), 'activated_at' => null])->save();

            return 1;
        }

        $sub->forceFill(['facts' => $check['facts'], 'reason' => $check['outside'] ? $check['reason'] : null, 'last_seen_at' => now(), 'resolved_at' => now()])->save();

        return 0;
    }
}
