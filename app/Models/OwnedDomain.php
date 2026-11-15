<?php

namespace App\Models;

use App\Models\Concerns\VisibleToAccount;
use App\Models\Contracts\VisibilityRoot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Um nome de domínio de uma conta ou de uma empresa: a fonte única da posse (ver DomainOwnership). Uma
 * linha por conta pessoal e nome, ou por empresa e nome (`company_id`; `sector_id` nulo = sem setor),
 * pedida por qualquer serviço (Notícias, Radar, testes de segurança, monitor de sites, webhook).
 * Comprovada (`verified_at`), vale para o nome e para os subdomínios dele. É raiz da visibilidade: na
 * empresa, quem vê e quem configura é o Visibility (DESENHO-5.3 §2.5); `user_id` é quem cadastrou.
 */
class OwnedDomain extends Model implements VisibilityRoot
{
    use VisibleToAccount;

    /** Empresa e setor só pelo DomainOwner/forceFill, nunca por atribuição em massa. */
    protected $guarded = ['company_id', 'sector_id'];

    /** O serviço que pediu o nome primeiro (`source`), como as telas dizem de onde veio a prova; `company`, a tela Domínios da empresa. */
    public const SOURCES = ['news', 'radar', 'tests', 'monitor', 'webhook', 'company'];

    /** Resultado da última conferência do TXT. */
    public const CHECKS = ['present', 'missing', 'unknown'];

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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /** Os subdomínios (R6, só da empresa): ativos, sugestões, tirados e os que deixaram de resolver. */
    public function subdomains(): HasMany
    {
        return $this->hasMany(OwnedSubdomain::class);
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

    /** Quando a posse cai se o TXT continuar sem aparecer (nulo: não cai, ou o TXT está lá). */
    public function fallsAt(): ?Carbon
    {
        return $this->isVerified() && ! $this->legacy && $this->unseen_since
            ? $this->unseen_since->copy()->addDays((int) config('secmon.ownership.grace_days'))
            : null;
    }
}
