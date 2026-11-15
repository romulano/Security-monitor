<?php

namespace Tests\Support;

use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Enrichment\EnricherManager;
use App\Services\Enrichment\EnrichmentResult;

/** O EnricherManager sem rede: anota quem foi lido e se veio com a matéria atual (known_report). */
class RecordingEnricher extends EnricherManager
{
    /** @var list<array{article: int, with_case: bool}> */
    public array $calls = [];

    public function __construct() {}

    public function enrich(Article $article, ?NewsCase $existing = null): array
    {
        $this->calls[] = ['article' => $article->id, 'with_case' => $existing !== null];

        return [new EnrichmentResult(
            title: 'PHP2Shell: RCE no PHP',
            summary: "Relatório com o artigo {$article->id}.",
            criticity: 'critical',
            driver: 'hermes',
        ), null];
    }

    /** @return list<int> */
    public function read(): array
    {
        return array_column($this->calls, 'article');
    }
}
