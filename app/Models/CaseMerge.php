<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Matéria que a fusão de matérias apagou: o uuid dela redireciona para a matéria
 * que juntou o conteúdo (`case_id`).
 */
class CaseMerge extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cves' => 'array',
            'snapshot' => 'array',
            'merged_at' => 'datetime',
        ];
    }

    public function newsCase(): BelongsTo
    {
        return $this->belongsTo(NewsCase::class, 'case_id');
    }
}
