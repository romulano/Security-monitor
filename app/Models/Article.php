<?php

namespace App\Models;

use App\Services\Enrichment\EnricherManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Article extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'indicators' => 'array',
            'manual_media' => 'array',
            'published_at' => 'datetime',
            'collected_at' => 'datetime',
            'is_first' => 'boolean',
            'is_new_info' => 'boolean',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function newsCase(): BelongsTo
    {
        return $this->belongsTo(NewsCase::class, 'case_id');
    }

    /** As tentativas de análise, da mais antiga à mais nova (Admin → Coletas); `attempts` é o contador da fila. */
    public function analysisAttempts(): HasMany
    {
        return $this->hasMany(ArticleAttempt::class)->orderBy('id');
    }

    /**
     * Artigos em que a IA falhou (limite do hermes, resposta sem JSON, driver sem
     * chave) e entrou a extração heurística: o erro do driver ficou no artigo.
     */
    public function scopeDriverError(Builder $query): void
    {
        $query->where(fn (Builder $q) => collect(EnricherManager::DRIVER_ERROR_PREFIXES)
            ->each(fn ($prefix) => $q->orWhere('error', 'like', str_replace(['%', '_'], ['\%', '\_'], $prefix).'%')));
    }
}
