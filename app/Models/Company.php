<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma empresa do plano empresarial: as contas dela (`company_user`), o token do TXT de posse
 * (`domain_token`, §4.4), os limites ajustados pelo admin do Security Monitor (`limit_*`; nulo = o padrão
 * da config) e as chaves. Quem cria, lê o limite efetivo e diz se está ativa é o Companies.
 */
class Company extends Model
{
    /** Os limites por empresa; cada um tem a coluna `limit_<nome>` e o padrão em `secmon.enterprise.limits`. */
    public const LIMITS = [
        'accounts', 'domains', 'subdomains', 'sectors', 'github_monitors', 'alert_profiles',
        'sector_channels', 'general_channels', 'webhooks', 'radar_seeds', 'daily_invites',
    ];

    /** Como a auditoria e as telas chamam cada limite. */
    public const LIMIT_LABELS = [
        'accounts' => 'contas', 'domains' => 'domínios', 'subdomains' => 'subdomínios por domínio', 'sectors' => 'setores',
        'github_monitors' => 'monitoramentos do GitHub', 'alert_profiles' => 'perfis de alerta', 'sector_channels' => 'canais por setor',
        'general_channels' => 'canais gerais', 'webhooks' => 'webhooks', 'radar_seeds' => 'sementes do Radar', 'daily_invites' => 'convites por dia',
    ];

    protected $fillable = ['name'];

    /** O token prova a posse dos domínios da empresa: só as telas da admin o mostram. */
    protected $hidden = ['domain_token'];

    protected function casts(): array
    {
        return [
            'require_2fa' => 'boolean',
            'enterprise_enabled_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /** As contas da empresa (também as desativadas), com o papel. */
    public function members(): HasMany
    {
        return $this->hasMany(CompanyMember::class);
    }

    /** Os convites (abertos, aceitos, cancelados e vencidos). */
    public function invites(): HasMany
    {
        return $this->hasMany(CompanyInvite::class);
    }

    /** Os setores da empresa (até `limit_sectors`, D2). */
    public function sectors(): HasMany
    {
        return $this->hasMany(Sector::class);
    }

    /** Os domínios da empresa (a posse é dela, §4.4), até `limit_domains` (R5). */
    public function ownedDomains(): HasMany
    {
        return $this->hasMany(OwnedDomain::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(CompanyMember::class)
            ->withPivot(['id', 'role', 'invited_at', 'joined_at', 'deactivated_at'])
            ->withTimestamps();
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }
}
