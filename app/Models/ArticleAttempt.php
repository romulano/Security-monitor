<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma tentativa de análise de um artigo (ver a migration): o gatilho, o driver,
 * o resultado e o erro técnico. Admin → Coletas mostra por ela se o artigo com
 * erro já foi refeito e como terminou.
 */
class ArticleAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** O que pediu a análise, com o nome da tela. */
    public const TRIGGERS = [
        'coleta' => 'coleta',
        'retentar' => 'retentar',
        'refazer-ia' => 'refazer com a IA',
        'refazer-materia' => 'refazer a matéria',
        'fusao' => 'fusão',
    ];

    /** Os gatilhos que analisam o artigo de novo. A fusão escreve a matéria, não a análise do artigo. */
    public const REDO = ['retentar', 'refazer-ia', 'refazer-materia'];

    public const RESULTS = ['ok' => 'ok', 'heuristica' => 'heurística', 'erro' => 'erro'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function failed(): bool
    {
        return $this->result !== 'ok';
    }
}
