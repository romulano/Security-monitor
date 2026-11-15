<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma mudança de estado de um problema (apareceu, piorou, foi resolvido, voltou). */
class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @var array<string, string> */
    public const KINDS = [
        'opened' => 'apareceu',
        'worsened' => 'piorou',
        'resolved' => 'resolvido',
        'reopened' => 'voltou',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SecuritySite::class, 'site_id');
    }
}
