<?php

namespace App\Services\Lookalike;

use App\Exceptions\ActionRefused;
use App\Jobs\ScanLookalikes;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\CompanyLimits;
use App\Services\Company\ItemSectors;
use App\Services\Company\Viewer;
use App\Services\Access\Visibility;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;
use App\Support\DomainName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A entrevista do Radar de domínios, separada da de notícias: o domínio
 * (passo 1), a marca (passo 2) e a posse com a ativação (passo 3).
 *
 * O domínio do Radar é uma linha própria, sem alerta. A posse é da conta
 * (DomainOwnership): o domínio já comprovado em qualquer serviço chega
 * comprovado, e o passo 3 não pede o TXT de novo. Escolhido na lista do passo
 * 1, o aceite do termo v3 de Notícias (que cobre os prints do site) vem junto.
 */
class RadarInterview
{
    public const LAST_STEP = 3;

    /** Título, explicação e subtítulo (no menu de passos) de cada passo. */
    public const STEPS = [
        1 => ['Domínio', 'O domínio do site que os seus clientes acessam. Cada domínio é um Radar; dá para adicionar outros depois.', 'o que vigiar'],
        2 => ['Marca', 'Com estes nomes geramos variações além do domínio, e a IA usa para reconhecer quem imita vocês.', 'nomes para as variações'],
        3 => ['Posse e ativação', 'Assim ninguém usa o Security Monitor para vigiar a marca dos outros.', 'registro TXT'],
    ];

    public function __construct(private DomainOwnership $ownership, private VariantGenerator $variants, private Visibility $visibility) {}

    /**
     * Domínios com a posse comprovada na conta (em qualquer serviço) que ainda
     * não têm Radar: o passo 1 oferece reaproveitar com um clique.
     *
     * @return Collection<int, OwnedDomain>
     */
    public function provenDomains(User $user, ?LookalikeWatch $except = null): Collection
    {
        // Os domínios que já têm Radar: na empresa, em qualquer setor (um Radar por domínio na empresa toda).
        $taken = $this->watches($user)->whereKeyNot($except?->id ?? 0)->with('domain')->get()->pluck('domain.domain')->filter()->all();

        return $this->ownership->proven($user)
            ->reject(fn (OwnedDomain $owned) => in_array($owned->domain, $taken, true))
            ->values();
    }

    /** Um Radar por domínio (na empresa, em qualquer setor). Na empresa, o Radar pode ser de um setor que a conta não vê: a mensagem não diz qual (Red Team da E2d1). */
    private function ensureNoOtherRadar(User $user, ?LookalikeWatch $watch, string $name, bool $fromList): void
    {
        if ($this->watches($user)->whereKeyNot($watch?->id ?? 0)->whereHas('domain', fn ($q) => $q->where('domain', $name))->exists()) {
            $where = $this->visibility->viewer($user)->kind === Viewer::PERSONAL ? '' : ' na empresa';
            throw ValidationException::withMessages([$fromList ? 'origem' : 'outro' => "Já existe um Radar para {$name}{$where}."]);
        }
    }

    /**
     * Na empresa não há "5 por criador" (E5d, P7): o teto é o limite de domínios. Sem ele, os nomes de baixo de um
     * domínio comprovado (que não gastam domínio, E3b) dariam Radares sem fim (Red Team B da E5d).
     */
    private function ensureCompanyRoom(Company $locked): void
    {
        $max = app(Companies::class)->limit($locked, 'domains');

        if (LookalikeWatch::query()->where('company_id', $locked->id)->count() >= $max) {
            throw new ActionRefused("A empresa já tem {$max} Radares, o máximo (um por domínio, até o limite de domínios). Para adicionar outro, exclua um Radar que não use mais. ".CompanyLimits::MORE);
        }
    }

    /** Os Radares que contam para "já existe": na conta pessoal, os dela; na empresa, os da empresa toda (o sistema conferindo). */
    private function watches(User $user): Builder
    {
        $viewer = $this->visibility->viewer($user);

        return $viewer->kind === Viewer::PERSONAL || $viewer->companyId === null
            ? LookalikeWatch::query()->visibleTo($user)
            : LookalikeWatch::query()->where('company_id', $viewer->companyId);
    }

    /** A conta cria Radares: a pessoal sempre; na empresa, a admin e quem responde por algum setor (o membro, não). */
    public function mayCreate(User $user): bool
    {
        return match ($this->visibility->viewer($user)->kind) {
            Viewer::PERSONAL, Viewer::ADMIN => true,
            Viewer::SECTORS => $this->visibility->sectorChoices($user)->isNotEmpty(),
            default => false,
        };
    }

    /** O que a tela de um passo mostra. */
    public function stepPage(User $user, ?LookalikeWatch $watch, int $step): array
    {
        return [
            'watch' => $watch,
            'domain' => $watch?->domain,
            'step' => $step,
            'steps' => self::STEPS,
            'text' => self::STEPS[$step],
            'proven' => $step === 1 && ! $watch?->isCompleted() ? $this->provenDomains($user, $watch) : collect(),
            'ownership' => $step === 3 && $watch?->domain ? $this->ownership->screenFor($watch->domain) : null,
            'samples' => $step === 2 && $watch ? $this->samples($watch) : ['hosts' => [], 'total' => 0],
            // O setor do Radar novo de empresa (o passo 1 só escolhe na criação; depois, "Trocar o setor" na tela do Radar).
            'sectors' => $step === 1 && ! $watch ? $this->visibility->sectorChoices($user) : collect(),
            'sectorless' => $step === 1 && ! $watch && $this->visibility->canUseNoSector($user),
        ];
    }

    /** Até onde a entrevista deste Radar já foi (sem Radar, só o passo 1). */
    public function reachable(?LookalikeWatch $watch): int
    {
        return $watch ? max(1, min(self::LAST_STEP, (int) $watch->step_reached)) : 1;
    }

    /**
     * Passo 1: o domínio, escolhido entre os já comprovados na conta
     * (`$provenId`) ou digitado. Na primeira vez cria o Radar, em rascunho, com
     * empresa e marcas vazias: quem vigia pode ser outra empresa, então o
     * cliente escreve os nomes no passo 2, sem copiar do alerta de notícias.
     */
    public function saveDomain(User $user, ?LookalikeWatch $watch, ?int $provenId, ?string $typed, ?int $sectorId = null): LookalikeWatch
    {
        if ($watch?->isCompleted()) {
            throw new ActionRefused('O domínio de um Radar ativado não muda. Para vigiar outro domínio, adicione outro Radar em Integração.');
        }

        if (! $watch && $user->reachedRadarLimit()) {
            throw new ActionRefused('Você chegou ao limite de '.LookalikeWatch::MAX_PER_USER.' Radares. Exclua um para adicionar outro.');
        }

        // Quem cria e onde (empresa e setor): conferido antes de tudo, nunca pelo formulário (Visibility::stampFor).
        $stamp = $watch ? null : $this->visibility->stampFor($user, $sectorId);

        $source = $provenId ? $this->provenDomains($user, $watch)->firstWhere('id', $provenId) : null;

        if ($provenId && ! $source) {
            throw ValidationException::withMessages(['origem' => 'Escolha um dos domínios da lista ou escreva outro.']);
        }

        $name = $source?->domain ?? DomainName::normalize($typed)
            ?? throw ValidationException::withMessages(['outro' => 'Informe um domínio válido, como empresa.com.br (sem IP, porta ou domínio interno).']);

        $this->ensureNoOtherRadar($user, $watch, $name, $source !== null);

        return DB::transaction(function () use ($user, $watch, $source, $name, $stamp) {
            // Radar novo de empresa: a empresa travada antes de conferir o setor de novo, porque apagar o setor também a
            // trava; um setor apagado no mesmo instante vira a mensagem, e não um erro de FK (Red Team da E2f).
            if ($stamp !== null && $stamp['company_id'] !== null) {
                $company = Company::query()->lockForUpdate()->findOrFail($stamp['company_id']);
                ItemSectors::ensureSectorExists($stamp['sector_id']);
                // Com a empresa travada, de novo: dois pedidos juntos (duas abas, a admin e um responsável) não dão dois
                // Radares para o mesmo domínio, e o total não passa do limite de domínios (Red Team B da E5d).
                $this->ensureNoOtherRadar($user, null, $name, $source !== null);
                $this->ensureCompanyRoom($company);
            } elseif ($watch?->company_id !== null) {
                Company::query()->lockForUpdate()->findOrFail($watch->company_id);
                $this->ensureNoOtherRadar($user, $watch, $name, $source !== null);
            }

            $current = $watch?->domain;
            // O dono da posse: a empresa do Radar (o token da empresa) ou a conta (DESENHO-5.3 §2.1). O Radar novo
            // ainda não existe quando a cópia do domínio nasce: o dono vem do carimbo.
            // A cópia e a linha nova da posse ficam com quem criou o Radar, não com quem editou o rascunho (Red Team da E2d1).
            $creator = $watch?->user_id ?? $user->id;
            $owner = $watch ? DomainOwner::of($watch->company_id, $creator, $watch->sector_id) : DomainOwnership::ownerOfStamp($stamp);

            // Mesmo domínio: fica como está (a posse do dono chega sozinha).
            if ($current && $current->domain === $name) {
                $this->ownership->apply($current);
                $watch->forceFill(['step_reached' => max($watch->step_reached, 2)])->save();

                return $watch;
            }

            // O aceite do termo v3 de notícias já autoriza os prints do site; o v2 não. Só de um alerta que a conta configura
            // (na conta pessoal, os dela): o aceite de outra conta da empresa, de outro setor, não vem junto.
            $news = $source ? MonitoredDomain::query()->ofAlerts()->whereIn('profile_id', AlertProfile::query()->manageableBy($user)->select('id'))
                ->where('domain', $name)->oldest('id')->get()->first(fn (MonitoredDomain $domain) => $domain->allowsReferenceCapture()) : null;

            $domain = MonitoredDomain::query()->create([
                'user_id' => $creator,
                'domain' => $name,
                'verify_token' => $this->ownership->txtToken($owner, $name),
                ...($news ? [
                    'consent_at' => $news->consent_at,
                    'consent_ip' => $news->consent_ip,
                    'consent_version' => $news->consent_version,
                ] : []),
            ]);
            $this->ownership->claim($owner, $name, 'radar', $domain->verify_token);
            $this->ownership->apply($domain, $watch ?? (new LookalikeWatch)->forceFill($stamp));

            if ($watch) {
                $watch->forceFill(['domain_id' => $domain->id, 'step_reached' => max($watch->step_reached, 2)])->save();
                $current?->delete();

                return $watch;
            }

            return LookalikeWatch::query()->forceCreate($stamp + [
                'domain_id' => $domain->id,
                'status' => 'draft',
                'scan_status' => 'pending',
                'company_name' => null,
                'brands' => [],
                'step_reached' => 2,
            ]);
        });
    }

    /** Passo 2: a empresa e os nomes da marca (repetidos e vazios saem). */
    public function saveBrand(LookalikeWatch $watch, string $company, array $brands): void
    {
        $clean = collect($brands)
            ->map(fn ($brand) => trim(preg_replace('/\s+/', ' ', (string) $brand)))
            ->filter()
            ->unique(fn ($brand) => mb_strtolower($brand))
            ->take(LookalikeWatch::MAX_BRANDS)
            ->values()
            ->all();

        $watch->forceFill([
            'company_name' => trim($company),
            'brands' => $clean,
            'step_reached' => max($watch->step_reached, 3),
        ])->save();
    }

    /** Botão "Verificar agora" do passo 3: a posse da conta ou o TXT; sem eles, recusa dizendo o que falta. */
    public function verify(LookalikeWatch $watch): void
    {
        $this->ownership->confirmRow($watch->domain, 'radar');
    }

    /**
     * "Ativar o Radar" (ou "Salvar", depois de ativado): grava o aceite dos
     * prints do site e, na primeira vez, liga o Radar. Com a posse comprovada a
     * primeira varredura começa já; sem ela, o Radar espera o TXT e começa
     * sozinho quando ele aparecer. Devolve `started` (varredura já na fila),
     * `waiting` (esperando o TXT) ou `saved` (Radar já ativado antes).
     */
    public function activate(LookalikeWatch $watch, bool $consent, ?string $ip): string
    {
        $domain = $watch->domain;
        $this->saveConsent($domain, $consent, $ip);

        if ($watch->isCompleted()) {
            return 'saved';
        }

        $this->ownership->apply($domain);
        $verified = $domain->isVerified();
        $watch->forceFill([
            'completed_at' => now(),
            'step_reached' => self::LAST_STEP,
            'status' => $verified ? 'active' : 'waiting',
            'next_scan_at' => $verified ? now() : null,
            'manual_scan_at' => $verified ? now() : null,
        ])->save();

        if ($verified) {
            ScanLookalikes::dispatch($watch->id);
        }

        return $verified ? 'started' : 'waiting';
    }

    /** Exclui o Radar: o domínio dele, os achados e os prints (do disco também). */
    public function delete(LookalikeWatch $watch): void
    {
        $domain = $watch->domain;
        $watch->deleteFiles();

        // O domínio do Radar só existe por causa dele; o banco apaga o Radar em cascata.
        $domain?->isRadarOnly() ? $domain->delete() : $watch->delete();
    }

    /** Aceite dos prints do site: dar no termo do Radar, manter o que já vale, ou revogar. */
    private function saveConsent(MonitoredDomain $domain, bool $consent, ?string $ip): void
    {
        $valid = $domain->consent_at && ! in_array($domain->consent_version, MonitoredDomain::NO_BROWSER_CONSENTS, true);

        if ($consent && ! $valid) {
            $domain->forceFill(['consent_at' => now(), 'consent_ip' => $ip, 'consent_version' => MonitoredDomain::RADAR_CONSENT])->save();
        } elseif (! $consent && $domain->consent_at) {
            // Revogar apaga os prints do site (RadarEnrollment).
            $domain->forceFill(['consent_at' => null, 'consent_ip' => null, 'consent_version' => null])->save();
        }
    }

    /**
     * Alguns dos nomes que a varredura vai conferir (um de cada técnica
     * primeiro, para mostrar a variedade) e quantos são ao todo.
     *
     * @return array{hosts: list<string>, total: int}
     */
    private function samples(LookalikeWatch $watch): array
    {
        $domain = $watch->domain?->domain;

        if (! $domain) {
            return ['hosts' => [], 'total' => 0];
        }

        $all = $this->variants->generate($domain, array_filter(array_merge($watch->brandNames(), [$watch->company_name])));
        $byTechnique = [];

        foreach ($all as $host => $technique) {
            $byTechnique[$technique][] = $host;
        }

        $hosts = [];

        for ($i = 0; $i < 3 && count($hosts) < 12; $i++) {
            foreach ($byTechnique as $list) {
                if (isset($list[$i]) && count($hosts) < 12) {
                    $hosts[] = $list[$i];
                }
            }
        }

        return ['hosts' => $hosts, 'total' => count($all)];
    }
}
