<?php

namespace App\Models;

use App\Models\Contracts\VisibleThroughRoot;
use App\Services\Lookalike\VariantGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Um nome parecido com o domínio do cliente que existe no DNS. */
class LookalikeDomain extends Model implements VisibleThroughRoot
{
    protected $guarded = [];

    /** Do mais grave para o menos grave. */
    public const RISKS = ['critical', 'high', 'watch', 'low'];

    public const RISK_LABELS = [
        'critical' => 'Crítico',
        'high' => 'Alto',
        'watch' => 'Vigiar',
        'low' => 'Baixo',
    ];

    public const STATE_LABELS = [
        'pending' => 'aguardando a visita',
        'no_site' => 'registrado, sem site',
        'offline' => 'site fora do ar',
        'parked' => 'estacionado, à venda ou página padrão',
        'blank' => 'página em branco',
        'challenge' => 'atrás de desafio anti-robô',
        'redirects_to_you' => 'redireciona para o seu site',
        'content' => 'site no ar',
        'gone' => 'não existe mais',
    ];

    public const VERDICT_LABELS = [
        'ours' => 'É nosso',
        'safe' => 'Não é ameaça',
        'threat' => 'É golpe',
    ];

    protected function casts(): array
    {
        return [
            'techniques' => 'array',
            'from_certificate' => 'boolean',
            'ips' => 'array',
            'mx' => 'array',
            'ns' => 'array',
            'registered_at' => 'datetime',
            'rdap' => 'array',
            'hosting' => 'array',
            'signals' => 'array',
            'verdict_at' => 'datetime',
            'risk_changed_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'rdap_checked_at' => 'datetime',
            'ai_reviewed_at' => 'datetime',
            'verdict_note_flagged' => 'boolean',
            'reports' => 'array',
        ];
    }

    public function watch(): BelongsTo
    {
        return $this->belongsTo(LookalikeWatch::class, 'watch_id');
    }

    public function captures(): HasMany
    {
        return $this->hasMany(LookalikeCapture::class, 'lookalike_id');
    }

    public function lastCapture(): BelongsTo
    {
        return $this->belongsTo(LookalikeCapture::class, 'last_capture_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(LookalikeAlert::class, 'lookalike_id');
    }

    /** O último aviso (novo ou subiu de nível), com o parecer da IA. */
    public function latestAlert(): HasOne
    {
        return $this->hasOne(LookalikeAlert::class, 'lookalike_id')->latestOfMany();
    }

    /** A IA segurou o último aviso: o cliente vê, mas a mensagem não saiu. */
    public function heldByAi(): bool
    {
        return $this->relationLoaded('latestAlert') && $this->latestAlert?->status === 'held';
    }

    /** O cliente já denunciou por algum caminho do kit? */
    public function reported(): bool
    {
        return collect($this->reports ?? [])->filter(fn ($r) => ! empty($r['at']))->isNotEmpty();
    }

    /** Denunciado e agora fora do ar (sumiu do DNS, sem site ou sem resposta). */
    public function takenDown(): bool
    {
        return $this->reported() && in_array($this->state, ['gone', 'no_site', 'offline'], true);
    }

    /** Ordem de gravidade para o ORDER BY. */
    public function scopeByRisk(Builder $query): Builder
    {
        return $query->orderByRaw("case risk when 'critical' then 0 when 'high' then 1 when 'watch' then 2 else 3 end")
            ->orderByDesc('score')
            ->orderByDesc('first_seen_at');
    }

    /** O cliente disse que é dele ou que não é ameaça: sai da lista principal. */
    public function isDismissed(): bool
    {
        return in_array($this->verdict, ['ours', 'safe'], true);
    }

    /** Nome como o cliente lê: "fatalmodеl.com" em vez de "xn--fatalmodl-…". */
    public function displayHost(): string
    {
        if (str_contains($this->host, 'xn--') && function_exists('idn_to_utf8')) {
            return idn_to_utf8($this->host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $this->host;
        }

        return $this->host;
    }

    public function isIdn(): bool
    {
        return str_contains($this->host, 'xn--');
    }

    /** Explica o punycode: acento ("examplé") ou letra de outro alfabeto (o "а" cirílico). */
    public function idnNote(): string
    {
        $display = $this->displayHost();

        return preg_match('/[^\x00-\x7F\x{00C0}-\x{017F}]/u', $display) ? 'usa letra de outro alfabeto, idêntica à latina' : 'usa letra com acento';
    }

    public function riskLabel(): string
    {
        return self::RISK_LABELS[$this->risk] ?? $this->risk;
    }

    public function stateLabel(): string
    {
        return self::STATE_LABELS[$this->state] ?? $this->state;
    }

    /** @return list<string> "tecla vizinha", "certificado novo"... */
    public function techniqueLabels(): array
    {
        $labels = array_map(fn ($key) => VariantGenerator::LABELS[$key] ?? $key, $this->techniques ?? []);

        if ($this->from_certificate) {
            $labels[] = 'certificado HTTPS';
        }

        return array_values(array_unique($labels));
    }

    /** "há 3 dias", "há 2 anos"; nulo sem data do registro. */
    public function registeredAgo(): ?string
    {
        if (! $this->registered_at) {
            return null;
        }

        $days = (int) $this->registered_at->diffInDays(now());

        return match (true) {
            $days < 1 => 'hoje',
            $days === 1 => 'há 1 dia',
            $days < 60 => "há {$days} dias",
            $days < 730 => 'há '.intdiv($days, 30).' meses',
            default => 'há '.intdiv($days, 365).' anos',
        };
    }

    public function hasMx(): bool
    {
        // MX nulo (RFC 7505, "."): o domínio declara que não recebe e-mail.
        return collect($this->mx ?? [])->reject(fn ($mx) => in_array($mx, ['', '.'], true))->isNotEmpty();
    }

    public function visibilityRoot(): ?Model
    {
        return $this->watch;
    }

    /** As imitações dos Radares que a conta vê. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn($this->qualifyColumn('watch_id'), LookalikeWatch::query()->visibleTo($user)->select('id'));
    }
}
