<?php

namespace App\Models;

use App\Models\Concerns\VisibleToAccount;
use App\Models\Contracts\VisibilityRoot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** O perfil que a entrevista monta para um cliente. Um por usuário. */
class AlertProfile extends Model implements VisibilityRoot
{
    use VisibleToAccount;

    /** Empresa e setor só pelo serviço (Visibility::stampFor + forceFill), nunca de um array da tela. */
    protected $guarded = [];

    /** Do mais grave para o menos grave. */
    public const LEVELS = ['critical', 'high', 'medium', 'low'];

    /** Alertas por cliente (um por produto ou site). */
    public const MAX_PER_USER = 5;

    private ?bool $hasSiblings = null;

    protected function casts(): array
    {
        return [
            'brands' => 'array',
            'sectors' => 'array',
            'regions' => 'array',
            'attack_types' => 'array',
            'custom_answers' => 'array',
            'tech_versions' => 'array',
            'include_brand_mentions' => 'boolean',
            'include_sector_news' => 'boolean',
            'notify_case_updates' => 'boolean',
            'percolated_at' => 'datetime',
            'completed_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(AlertProfileAnswer::class, 'profile_id');
    }

    public function techs(): BelongsToMany
    {
        return $this->belongsToMany(TechCatalog::class, 'alert_profile_techs', 'profile_id', 'tech_id')
            ->withPivot(['source', 'confidence', 'created_at']);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(AlertProfileTerm::class, 'profile_id');
    }

    public function domains(): HasMany
    {
        return $this->hasMany(MonitoredDomain::class, 'profile_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(AlertMatch::class, 'profile_id');
    }

    /** Como o alerta aparece para o cliente, no Slack e no admin. */
    public function displayName(): string
    {
        return $this->name ?: ($this->company_name ?: 'Alerta '.$this->id);
    }

    /**
     * O cliente tem outros alertas além deste? O de empresa sempre leva o `?alerta=` na URL: cada conta vê um conjunto
     * diferente, e "sem parâmetro = o primeiro" poderia apontar para outro alerta (DESENHO-5.2 §4.3).
     */
    public function hasSiblings(): bool
    {
        // O alerta novo, antes de gravar o passo 1, ainda não existe: sem irmãos nem ?alerta=.
        if (! $this->exists) {
            return false;
        }

        return $this->hasSiblings ??= static::query()->visibleTo($this->user)->whereKeyNot($this->id)->exists();
    }

    /**
     * Parâmetro que diz qual alerta uma URL edita. Quem tem um alerta só
     * continua com as URLs de sempre; sem o parâmetro vale o primeiro alerta.
     */
    public function urlParams(array $extra = []): array
    {
        // O alerta novo de empresa, antes de gravar o passo 1: ainda sem id.
        if (! $this->exists) {
            return $extra;
        }

        return $extra + ($this->hasSiblings() ? ['alerta' => $this->id] : []);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    /** Respostas como [chave => valor], para reabrir a entrevista como estava. */
    public function answerMap(): array
    {
        return $this->answers->mapWithKeys(fn (AlertProfileAnswer $a) => [$a->question_key => $a->value])->all();
    }

    /** Níveis que passam no mínimo escolhido. */
    public function allowedLevels(): array
    {
        $index = array_search($this->min_criticity, self::LEVELS, true);

        return array_slice(self::LEVELS, 0, $index === false ? 2 : $index + 1);
    }

    public function levelLabel(): string
    {
        return match ($this->min_criticity) {
            'critical' => 'Somente Critical',
            'high' => 'High e acima',
            'medium' => 'Medium e acima',
            default => 'Todos os níveis',
        };
    }
}
