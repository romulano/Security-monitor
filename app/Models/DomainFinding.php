<?php

namespace App\Models;

use App\Models\Contracts\VisibleThroughRoot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainFinding extends Model implements VisibleThroughRoot
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'found_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(MonitoredDomain::class, 'domain_id');
    }

    public function tech(): BelongsTo
    {
        return $this->belongsTo(TechCatalog::class, 'tech_id');
    }

    public function confidenceLabel(): string
    {
        return $this->confidence >= 80 ? 'alta' : ($this->confidence >= 50 ? 'média' : 'baixa');
    }

    public function visibilityRoot(): ?Model
    {
        return $this->domain?->visibilityRoot();
    }

    /** As sugestões dos domínios que a conta vê. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn($this->qualifyColumn('domain_id'), MonitoredDomain::query()->visibleTo($user)->select('id'));
    }
}
