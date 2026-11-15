<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma versão do relatório que passou pelo revisor: quantas rodadas, o parecer
 * de cada uma e o que foi pedido (ver a migration de `report_reviews`).
 */
class ReportReview extends Model
{
    public const APPROVED = 'approved';

    public const PENDING = 'pending';

    public const SKIPPED = 'skipped';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rounds' => 'array',
            'adjustments' => 'integer',
            'calls' => 'integer',
            'seconds' => 'integer',
        ];
    }

    public function newsCase(): BelongsTo
    {
        return $this->belongsTo(NewsCase::class, 'case_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** A revisão em vigor de cada matéria: a da versão mais nova do relatório. */
    public function scopeLatestPerCase(Builder $query): void
    {
        $query->whereIn('id', static::query()->selectRaw('max(id)')->whereNotNull('case_id')->groupBy('case_id'));
    }

    /** Os problemas do último parecer (o que ficou em aberto quando a revisão está pendente). */
    public function lastProblems(): array
    {
        return collect($this->rounds ?? [])->last()['problems'] ?? [];
    }
}
