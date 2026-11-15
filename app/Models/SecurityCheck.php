<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma varredura de um site: nota, estado e o resultado de cada verificação (só valores curtos). */
class SecurityCheck extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'tls_not_after' => 'datetime',
            'results' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SecuritySite::class, 'site_id');
    }
}
