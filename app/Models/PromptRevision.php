<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptRevision extends Model
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A revisão em uso de cada trecho: a mais nova. */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereIn('id', static::query()->selectRaw('max(id)')->groupBy('key'));
    }
}
