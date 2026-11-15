<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Um site do admin na monitoria de segurança (Admin → Segurança). */
class SecuritySite extends Model
{
    protected $guarded = [];

    /** Prefixo do TXT de posse, o mesmo do domínio de cliente (secmon-verify=<token>). */
    public const TXT_PREFIX = MonitoredDomain::TXT_PREFIX;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'consent_at' => 'datetime',
            'ownership_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
            'last_check_at' => 'datetime',
            'manual_check_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(SecurityCheck::class, 'site_id');
    }

    public function lastCheck(): HasOne
    {
        return $this->hasOne(SecurityCheck::class, 'site_id')->latestOfMany('started_at');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(SecurityFinding::class, 'site_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SecurityEvent::class, 'site_id');
    }

    /** Ligados e com a hora de verificar vencida (ou nunca verificados). */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('enabled', true)->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()));
    }

    /** O valor do registro TXT que prova a posse do domínio. */
    public function txtValue(): string
    {
        return self::TXT_PREFIX.$this->verify_token;
    }

    public function isOwned(): bool
    {
        return $this->ownership !== null;
    }

    /** Já passou do prazo desde a última varredura (ou nunca houve)? O agendador pode ter parado. */
    public function isStale(int $hours): bool
    {
        return $this->enabled && (! $this->last_check_at || $this->last_check_at->lt(now()->subHours($hours)));
    }
}
