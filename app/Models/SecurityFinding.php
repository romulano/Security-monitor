<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O estado atual de um problema do site: aberto enquanto a verificação falha, resolvido quando passa. */
class SecurityFinding extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SecuritySite::class, 'site_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
