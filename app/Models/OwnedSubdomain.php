<?php

namespace App\Models;

use App\Models\Contracts\VisibleThroughRoot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um subdomínio de um domínio comprovado da empresa (R6, DESENHO-5.3 §3): coberto pela posse do domínio de cima (sem
 * TXT próprio), até 20 ativos por domínio, fora dos 15 domínios. Filho do domínio: quem vê o domínio vê os subdomínios,
 * e quem configura o domínio configura os subdomínios (CompanySubdomains).
 */
class OwnedSubdomain extends Model implements VisibleThroughRoot
{
    protected $guarded = [];

    public const ORIGINS = ['passive', 'manual'];

    public const STATUSES = ['active', 'suggested', 'ignored', 'gone'];

    /** O motivo do SubdomainCheck (ou do teto) como a tela e o e-mail dizem. */
    public const REASONS = [
        'cname_fora' => 'aponta (CNAME) para um serviço de fora',
        'delegado' => 'o DNS dele fica em servidores de fora',
        'zona_filha' => 'fica numa parte do domínio entregue a servidores de DNS de fora',
        'zona_desconhecida' => 'não deu para conferir quem controla o DNS dele',
        'dns_mudo' => 'o DNS não respondeu',
        'sem_ip' => 'não resolve (sem endereço)',
        'teto' => 'acima do teto de subdomínios do domínio',
        'curinga' => 'só responde pelo curinga do DNS (*.domínio): pode não existir de verdade',
    ];

    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function ownedDomain(): BelongsTo
    {
        return $this->belongsTo(OwnedDomain::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Aponta para fora da zona da empresa (CNAME para terceiro, delegação de fora): nunca infraestrutura própria (P5). */
    public function isOutside(): bool
    {
        return (bool) ($this->facts['outside'] ?? false);
    }

    /** O motivo em português, com o destino do CNAME quando há (o texto é do DNS da própria empresa). */
    public function reasonLabel(): ?string
    {
        if ($this->reason === null) {
            return null;
        }

        $label = self::REASONS[$this->reason] ?? $this->reason;
        $target = $this->reason === 'cname_fora' ? (array_values(array_filter((array) ($this->facts['cname'] ?? [])))[0] ?? null) : null;

        return $target ? "{$label} ({$target})" : $label;
    }

    public function visibilityRoot(): ?Model
    {
        return $this->ownedDomain;
    }

    /** Os subdomínios dos domínios que a conta vê. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn($this->qualifyColumn('owned_domain_id'), OwnedDomain::query()->visibleTo($user)->select('id'));
    }
}
