<?php

namespace App\Models;

use App\Models\Concerns\VisibleToAccount;
use App\Models\Contracts\VisibilityRoot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Um nome de domínio da conta: a fonte única da posse (ver DomainOwnership). Uma linha por conta e
 * nome, pedida por qualquer serviço (Notícias, Radar, testes de segurança, monitor de sites,
 * webhook). Instalação self-hosted, de um dono só: nasce já comprovada (`verified_at`), sem prova por
 * registro TXT no DNS, e vale para o nome e para os subdomínios dele.
 */
class OwnedDomain extends Model implements VisibilityRoot
{
    use VisibleToAccount;

    /** Empresa e setor só pelo DomainOwner/forceFill, nunca por atribuição em massa. */
    protected $guarded = [];

    /** O serviço que pediu o nome primeiro (`source`); `company`, a tela Domínios da empresa. */
    public const SOURCES = ['news', 'radar', 'tests', 'monitor', 'webhook', 'company', 'keys'];

    /** `keys_listing`: cadastrado direto em Chaves (fica sem outro serviço usando o nome). */
    public const KEYS_ADDED = 'added';

    /** `keys_listing`: herdado de outro serviço e tirado de Chaves. */
    public const KEYS_REMOVED = 'removed';

    protected static function booted(): void
    {
        // Instalação de um dono só: todo domínio nasce comprovado, sem prova por TXT no DNS.
        static::creating(fn (self $owned) => $owned->verified_at ??= now());
    }

    protected function casts(): array
    {
        return [
            'tokens' => 'array',
            'legacy' => 'boolean',
            'verified_at' => 'datetime',
            'checked_at' => 'datetime',
            'unseen_since' => 'datetime',
            'lost_at' => 'datetime',
            'waiting_since' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A varredura de chaves deste domínio, se a conta a ativou. */
    public function keySite(): HasOne
    {
        return $this->hasOne(KeySite::class);
    }

    /** As chaves achadas no site (pela varredura de chaves e pelo mapeamento dos testes). */
    public function exposedKeys(): HasMany
    {
        return $this->hasMany(ExposedKey::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** Os que aparecem em Chaves expostas: todos menos os tirados de lá. */
    public function scopeListedInKeys(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('keys_listing')->orWhere('keys_listing', '!=', self::KEYS_REMOVED));
    }
}
