<?php

namespace App\Services\Enrichment;

use App\Models\Article;
use App\Models\NewsCase;

interface Enricher
{
    /**
     * Analisa um artigo coletado. Quando $existing é informado, o artigo é uma
     * atualização de um caso já existente — o driver deve dizer se traz
     * informação nova e qual é o delta.
     */
    public function enrich(Article $article, ?NewsCase $existing = null): EnrichmentResult;

    public function name(): string;

    /** Está utilizável (chave configurada, etc.)? */
    public function isReady(): bool;
}
