<?php

namespace App\Services\Enrichment;

use App\Models\Article;
use App\Models\NewsCase;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolve o driver ativo (configurado no admin) e cai para a extração
 * heurística quando a IA está desligada, sem chave ou indisponível — assim uma
 * falha de provedor não faz o crawler perder a notícia.
 */
class EnricherManager
{
    /**
     * Como começa o erro que enrich() devolve quando a IA falhou e entrou a
     * heurística ("hermes: …", "Driver x sem credencial…"). O CaseWriter zera o
     * `error` do artigo ao gravar e o job grava este por cima: artigo com matéria e
     * erro assim ficou sem a análise da IA (Admin → Coletas, "Refazer com a IA").
     */
    public const DRIVER_ERROR_PREFIXES = ['hermes: ', 'claude: ', 'openai: ', 'openrouter: ', 'Driver '];

    public function driver(?string $name = null): Enricher
    {
        $name ??= (string) Settings::get('enricher.driver');

        return match ($name) {
            'claude' => new ClaudeEnricher,
            'openai' => new OpenAiEnricher,
            'openrouter' => new OpenRouterEnricher,
            'hermes' => new HermesEnricher,
            default => new HeuristicEnricher,
        };
    }

    public function available(): array
    {
        return [
            'claude' => new ClaudeEnricher,
            'openai' => new OpenAiEnricher,
            'openrouter' => new OpenRouterEnricher,
            'hermes' => new HermesEnricher,
            'heuristic' => new HeuristicEnricher,
        ];
    }

    /** @return array{0: EnrichmentResult, 1: ?string} resultado e erro do driver primário */
    public function enrich(Article $article, ?NewsCase $existing = null): array
    {
        if (! Settings::bool('enricher.enabled')) {
            return [(new HeuristicEnricher)->enrich($article, $existing), null];
        }

        $driver = $this->driver();

        if (! $driver->isReady()) {
            return [
                (new HeuristicEnricher)->enrich($article, $existing),
                "Driver {$driver->name()} sem credencial; usando extração heurística.",
            ];
        }

        try {
            return [$driver->enrich($article, $existing), null];
        } catch (Throwable $e) {
            Log::warning('Enriquecimento falhou, caindo para heurística', [
                'driver' => $driver->name(),
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);

            return [
                (new HeuristicEnricher)->enrich($article, $existing),
                $driver->name().': '.$e->getMessage(),
            ];
        }
    }
}
