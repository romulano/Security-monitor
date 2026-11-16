<?php

namespace App\Models;

use App\Models\Contracts\VisibleThroughRoot;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Domínio do cliente: aceite e resultado da descoberta. Com `profile_id`, é o
 * domínio de um alerta de notícias (passo 5); sem ele, é o domínio de um Radar
 * de domínios (entrevista do Radar) ou a cópia da stack de testes de segurança
 * (`pentestDomain`). Esses dois nunca passam pela descoberta de tecnologias.
 *
 * Instalação self-hosted, de um dono só: todo domínio nasce comprovado
 * (`verified_at`), sem prova por registro TXT no DNS (ver DomainOwnership).
 * `verify_token` não é mais gerado nem conferido; a coluna continua NOT NULL
 * no banco, por isso o valor sentinela.
 */
class MonitoredDomain extends Model implements VisibleThroughRoot
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $domain) {
            // O domínio de um alerta é do dono do alerta.
            $domain->user_id ??= $domain->profile_id ? AlertProfile::query()->whereKey($domain->profile_id)->value('user_id') : null;
            // Instalação de um dono só: nasce comprovado, sem prova por TXT no DNS.
            $domain->verified_at ??= now();
            // `verify_token` ainda é NOT NULL no banco; nada mais gera nem confere este valor.
            $domain->verify_token ??= 'sem-prova-de-posse';
        });

        // Sem prova por TXT, o registro de posse é só a lista dos domínios em uso: quando o último serviço
        // solta o nome, ele sai também (e, em cascata, a varredura e as chaves achadas dele em Chaves). O
        // cadastrado direto em Chaves fica: lá ele é um serviço próprio.
        static::deleted(function (self $domain) {
            if ($domain->user_id && ! self::query()->where('user_id', $domain->user_id)->where('domain', $domain->domain)->exists()) {
                OwnedDomain::query()->where('user_id', $domain->user_id)->where('domain', $domain->domain)
                    ->where(fn ($q) => $q->whereNull('keys_listing')->orWhere('keys_listing', '!=', OwnedDomain::KEYS_ADDED))
                    ->delete();
            }
        });
    }

    /** Termos que só autorizavam a página inicial; os seguintes incluem a página de erro e o blog. */
    public const HOME_ONLY_CONSENTS = ['v1'];

    /** Varredura completa nunca mais de 1 vez neste intervalo, mesmo que o admin peça menos. */
    public const MIN_SCAN_INTERVAL_DAYS = 30;

    /** Releitura das páginas barradas (depois de liberar o robô no firewall): 1 por dia. */
    public const WALLS_RETRY_HOURS = 24;

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'verify_checked_at' => 'datetime',
            'consent_at' => 'datetime',
            'last_run_at' => 'datetime',
            'last_scan_at' => 'datetime',
            'walls_retry_at' => 'datetime',
            'next_run_at' => 'datetime',
            'last_log' => 'array',
            'hints' => 'array',
            'dns_suggestions' => 'array',
            'dns_checked_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AlertProfile::class, 'profile_id');
    }

    /** Só os domínios de alertas de notícias (os do Radar não passam pela descoberta). */
    public function scopeOfAlerts(Builder $query): Builder
    {
        return $query->whereNotNull('profile_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Domínio de um Radar (sem alerta de notícias). */
    public function isRadarOnly(): bool
    {
        return $this->profile_id === null;
    }

    public function findings(): HasMany
    {
        return $this->hasMany(DomainFinding::class, 'domain_id');
    }

    /** O Radar de domínios deste domínio (só domínios sem alerta têm). */
    public function lookalikeWatch(): HasOne
    {
        return $this->hasOne(LookalikeWatch::class, 'domain_id');
    }

    /** A inscrição na stack de testes de segurança dona desta cópia (só a cópia da stack tem). */
    public function pentestDomain(): HasOne
    {
        return $this->hasOne(PentestDomain::class, 'domain_id');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function hasConsent(): bool
    {
        return $this->consent_at !== null;
    }

    /** Termos anteriores ao v3 não autorizam abrir páginas do site com navegador (referência do Radar). */
    public const NO_BROWSER_CONSENTS = ['v1', 'v2'];

    /** Termo do Radar (passo 3 da entrevista dele): só os prints de até 3 páginas do site, 1 vez por mês. */
    public const RADAR_CONSENT = 'radar-v1';

    /**
     * O Radar pode guardar prints de 3 páginas do site: posse comprovada e o
     * termo do Radar ou o v3 (ou posterior) da entrevista de notícias.
     */
    public function allowsReferenceCapture(): bool
    {
        return $this->hasConsent() && $this->isVerified() && $this->consent_version && ! in_array($this->consent_version, self::NO_BROWSER_CONSENTS, true);
    }

    /** Pode sofrer descoberta: domínio de alerta, com aceite e posse comprovada, nessa ordem. */
    public function canDiscover(): bool
    {
        return ! $this->isRadarOnly() && $this->hasConsent() && $this->isVerified();
    }

    /**
     * Páginas que a última descoberta não leu por causa de desafio anti-robô.
     *
     * @return list<array{tipo: string, texto: string, url?: string, por?: string}>
     */
    public function walls(): array
    {
        return array_values(array_filter($this->hints ?? [], fn ($hint) => is_array($hint) && ($hint['tipo'] ?? null) === 'bloqueio'));
    }

    /** A Cloudflare está na frente do site (achado não removido pelo cliente, ou foi ela que barrou). */
    public function usesCloudflare(): bool
    {
        if (collect($this->walls())->contains('por', 'cloudflare')) {
            return true;
        }

        $id = TechCatalog::query()->where('slug', 'cloudflare')->value('id');

        return $id !== null && $this->findings->contains(fn (DomainFinding $f) => $f->tech_id === $id && $f->status !== 'ignored');
    }

    /**
     * Expressão da regra Skip que o cliente cola na Cloudflare: só o
     * User-Agent do Security Monitor e só os endereços que a descoberta lê, para quem
     * se passar pelo SecurityMonitorBot não ganhar passe livre no resto do site.
     */
    public function cloudflareExpression(): string
    {
        $paths = collect(['/', '/blog/'])
            ->merge(collect($this->walls())->map(fn ($wall) => parse_url($wall['url'] ?? '', PHP_URL_PATH) ?: '/'))
            ->unique()->values();
        $ips = config('secmon.alerts.discovery.egress_ips', []);

        return '(http.user_agent contains "SecurityMonitorBot"'
            .' and (http.request.uri.path in {'.$paths->map(fn ($p) => '"'.$p.'"')->implode(' ').'} or http.request.uri.path contains "/secmon-check-")'
            .($ips ? ' and ip.src in {'.implode(' ', $ips).'}' : '')
            .')';
    }

    public static function scanIntervalDays(): int
    {
        return max(self::MIN_SCAN_INTERVAL_DAYS, Settings::int('alerts.discovery_every_days'));
    }

    /** Já pode varrer de novo? Nunca varrido, ou a última varredura tem 30 dias ou mais. */
    public function scanDue(): bool
    {
        return $this->last_scan_at === null || $this->last_scan_at->copy()->addDays(self::scanIntervalDays())->lessThanOrEqualTo(now());
    }

    /** Quando a próxima varredura completa fica liberada (nulo: já está). */
    public function nextScanAt(): ?Carbon
    {
        return $this->scanDue() ? null : $this->last_scan_at->copy()->addDays(self::scanIntervalDays());
    }

    /** Quando o cliente pode pedir de novo a releitura das páginas barradas (nulo: já pode). */
    public function wallsRetryAvailableAt(): ?Carbon
    {
        $at = $this->walls_retry_at?->copy()->addHours(self::WALLS_RETRY_HOURS);

        return $at && $at->isFuture() ? $at : null;
    }

    public function allowsMorePages(): bool
    {
        return $this->hasConsent() && ! in_array($this->consent_version, self::HOME_ONLY_CONSENTS, true);
    }

    public function visibilityRoot(): ?Model
    {
        return $this->profile ?? $this->lookalikeWatch;
    }

    /** O domínio de um alerta ou de um Radar que a conta vê. A cópia da posse do webhook e a dos testes não têm raiz: não aparecem. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereIn($this->qualifyColumn('profile_id'), AlertProfile::query()->visibleTo($user)->select('id'))
            ->orWhereIn($this->qualifyColumn('id'), LookalikeWatch::query()->visibleTo($user)->select('domain_id')));
    }
}
