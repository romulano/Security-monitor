<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um domínio comprovado ativado para a varredura de chaves (ver a migration): ativo ou pausado,
 * o estado da varredura e o resultado da última, só com contagens.
 */
class KeySite extends Model
{
    protected $guarded = [];

    public const STATUSES = ['active', 'paused'];

    public const SCAN_STATUSES = ['idle', 'queued', 'running'];

    public const RESULTS = ['ok', 'partial', 'failed'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'last_scan_at' => 'datetime',
            'next_scan_at' => 'datetime',
            'last_summary' => 'array',
            'extra_paths' => 'array',
            'scan_state' => 'array',
        ];
    }

    public function ownedDomain(): BelongsTo
    {
        return $this->belongsTo(OwnedDomain::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** As chaves do domínio comprovado (de qualquer fonte: a varredura e o mapeamento dos testes). */
    public function keys(): HasMany
    {
        return $this->hasMany(ExposedKey::class, 'owned_domain_id', 'owned_domain_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isBusy(): bool
    {
        return in_array($this->scan_status, ['queued', 'running'], true);
    }
}
