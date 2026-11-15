<?php

namespace App\Services\Domains;

use App\Exceptions\ActionRefused;
use App\Models\Company;
use App\Models\OwnedDomain;
use App\Models\OwnedSubdomain;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\CompanyAudit;
use App\Services\Company\CompanyDomainNotices;
use App\Services\Company\Visibility;
use App\Support\DomainName;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Os subdomínios cadastrados à mão (E3b, DESENHO-5.3 §3.2): a posse do domínio de cima da empresa cobre o subdomínio
 * (sem TXT próprio), até 20 ativos por domínio, fora dos 15 domínios (R6). Só na empresa (P4).
 *
 * Quem cadastra e tira é quem configura o domínio: a admin; quem responde pelo setor dele; o sem setor, só a admin
 * (R14). O nome tem de terminar no domínio comprovado e resolver no DNS; o que aponta para fora da zona da empresa
 * (CNAME para terceiro, delegação de fora) fica ativo com a marca `outside` (P5): a pessoa escolheu, mas o Radar nunca
 * o trata como infraestrutura própria nem tira print dele.
 */
class CompanySubdomains
{
    public function __construct(
        private Visibility $visibility,
        private Companies $companies,
        private CompanyAudit $audit,
        private CompanyDomainNotices $notices,
        private SubdomainCheck $dns,
    ) {}

    public function add(User $by, OwnedDomain $domain, string $typed): OwnedSubdomain
    {
        $this->ensureManages($by, $domain);

        if ($domain->verified_at === null) {
            throw new ActionRefused("Comprove a posse de {$domain->domain} antes de cadastrar subdomínios.");
        }

        $name = self::normalize($typed)
            ?? throw new ActionRefused('Informe um nome válido, como login.'.$domain->domain.' (sem curinga, porta ou endereço).');

        if (! str_ends_with($name, '.'.$domain->domain)) {
            throw new ActionRefused("O subdomínio precisa terminar em .{$domain->domain}.");
        }

        // O DNS antes da trava: a consulta pode demorar, e a trava segura o domínio para quem cadastra junto.
        $check = $this->dns->check($domain->domain, $name);

        if ($check['resolves'] === null) {
            throw new ActionRefused("Não conseguimos consultar o DNS de {$name} agora. Tente de novo em alguns minutos.");
        }

        if (! $check['resolves']) {
            throw new ActionRefused("{$name} não resolve no DNS (sem endereço). Cadastre um subdomínio que já está no ar.");
        }

        return DB::transaction(function () use ($by, $domain, $name, $check) {
            $locked = OwnedDomain::query()->lockForUpdate()->findOrFail($domain->id);
            $company = Company::query()->findOrFail($locked->company_id);
            $current = $locked->subdomains()->where('name', $name)->first();

            if ($current?->isActive()) {
                throw new ActionRefused("{$name} já está entre os subdomínios de {$locked->domain}.");
            }

            $limit = $this->companies->limit($company, 'subdomains');
            $active = $locked->subdomains()->where('status', 'active')->count();

            if ($active >= $limit) {
                throw new ActionRefused("{$locked->domain} chegou ao limite de {$limit} subdomínios. Tire um para cadastrar outro.");
            }

            $values = [
                'status' => 'active',
                'reason' => $check['outside'] ? $check['reason'] : null,
                'facts' => $check['facts'],
                'last_seen_at' => now(),
                'resolved_at' => now(),
                'activated_at' => now(),
                'activated_by' => $by->id,
            ];
            // A origem fica a da 1ª vez (a sugestão da descoberta aceita continua `passive`).
            $sub = $current ?? new OwnedSubdomain(['owned_domain_id' => $locked->id, 'name' => $name, 'origin' => 'manual', 'first_seen_at' => now()]);
            $sub->forceFill($values)->save();

            $this->audit->record($company, $by, 'subdomain.added', targetLabel: $name, data: array_filter([
                'domínio' => $locked->domain,
                'fora da zona' => $check['outside'] ? ($check['reason'] ?? 'sim') : null,
            ]));
            DB::afterCommit(fn () => $this->notices->limit($company, 'subdomains', $active + 1, $limit, $locked));

            return $sub;
        });
    }

    /**
     * O que a tela do domínio mostra: os ativos (com `outside`), as sugestões e os tirados, cada um com o motivo; o uso
     * contra o teto; e se a conta cadastra e tira (quem só vê, vê a lista sem os botões).
     *
     * @return array{active: Collection, suggested: Collection, ignored: Collection, used: int, limit: int, manages: bool}
     */
    public function screen(User $user, OwnedDomain $domain): array
    {
        $all = $domain->subdomains()->orderBy('name')->get()->groupBy('status');
        $company = Company::query()->findOrFail($domain->company_id);

        return [
            'active' => $all->get('active', collect()),
            'suggested' => $all->get('suggested', collect()),
            'ignored' => $all->get('ignored', collect())->merge($all->get('gone', collect()))->sortBy('name')->values(),
            'used' => $all->get('active', collect())->count(),
            'limit' => $this->companies->limit($company, 'subdomains'),
            'manages' => $this->visibility->manages($user, $domain),
        ];
    }

    /** Tira o subdomínio: fica `ignored`, e a descoberta passiva não o traz de volta (dá para cadastrar de novo). */
    public function remove(User $by, OwnedSubdomain $sub): void
    {
        $this->ensureManages($by, $sub->ownedDomain);

        DB::transaction(function () use ($by, $sub) {
            $locked = OwnedDomain::query()->lockForUpdate()->findOrFail($sub->owned_domain_id);
            $company = Company::query()->findOrFail($locked->company_id);
            $current = OwnedSubdomain::query()->lockForUpdate()->findOrFail($sub->id);

            if ($current->status === 'ignored') {
                return;
            }

            $current->forceFill(['status' => 'ignored', 'activated_at' => null, 'activated_by' => null])->save();
            $this->audit->record($company, $by, 'subdomain.removed', targetLabel: $current->name, data: ['domínio' => $locked->domain]);
            $active = $locked->subdomains()->where('status', 'active')->count();
            DB::afterCommit(fn () => $this->notices->limit($company, 'subdomains', $active, $this->companies->limit($company, 'subdomains'), $locked));
        });
    }

    /**
     * O nome digitado: minúsculo, sem esquema, caminho nem ponto final, IDN em punycode. Ao contrário do domínio, o
     * `www.` fica (é um subdomínio como outro qualquer). Curinga, IP, porta e nome interno não valem.
     */
    public static function normalize(?string $typed): ?string
    {
        $value = mb_strtolower(trim((string) $typed));
        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = rtrim((string) preg_replace('#[/?\#].*$#', '', $value), '.');

        if ($value === '' || str_contains($value, '*') || str_contains($value, '@') || str_contains($value, ':')) {
            return null;
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $value)) {
            $value = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $value;
        }

        return DomainName::isValid($value) ? $value : null;
    }

    /** Quem configura o domínio (R14); quem não vê, nem sabe que ele existe. Só domínio de empresa (P4). */
    private function ensureManages(User $by, OwnedDomain $domain): void
    {
        if ($domain->company_id === null) {
            throw new ActionRefused('Subdomínios são do plano empresarial.');
        }

        if (! $this->visibility->manages($by, $domain)) {
            throw new ActionRefused($this->visibility->sees($by, $domain)
                ? 'Só a admin e quem responde pelo setor do domínio cadastram e tiram subdomínios.'
                : 'Domínio não encontrado.');
        }
    }
}
