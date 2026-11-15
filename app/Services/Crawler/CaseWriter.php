<?php

namespace App\Services\Crawler;

use App\Jobs\ExplainExploit;
use App\Jobs\ExplainVulnerability;
use App\Jobs\MatchCaseToProfiles;
use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Exploits\ExploitLinker;
use App\Services\Search\CaseIndex;
use Illuminate\Support\Facades\DB;

/**
 * Persiste o resultado do enriquecimento: cria um caso novo ou acrescenta o
 * artigo a um caso existente, sempre no mesmo formato das matérias analisadas.
 */
class CaseWriter
{
    public function __construct(private CaseIndex $index, private CaseResolver $resolver, private ExploitLinker $exploits) {}

    /**
     * Abre um caso a partir do primeiro artigo. Com $into, reescreve esse caso
     * no lugar (mesmo uuid, mesmo link) em vez de criar outro — é o que a
     * reanálise de um caso específico usa.
     */
    public function createCase(Article $article, EnrichmentResult $result, ?NewsCase $into = null): NewsCase
    {
        $case = DB::transaction(function () use ($article, $result, $into) {
            $case = ($into ?? new NewsCase)->forceFill([
                'title' => $result->title,
                'title_pt' => $result->titlePt,
                'criticity' => $result->criticity,
                'summary' => $result->summary,
                'summary_pt' => $result->summaryPt,
                'indicators' => $result->indicators,
                'victim_sectors' => $result->values('victim_sectors'),
                'threat_actors' => $result->values('threat_actors'),
                'cves' => $result->values('cves'),
                'first_at' => $article->collected_at,
                'latest_at' => $article->collected_at,
                'first_published_at' => $article->published_at,
                'last_published_at' => $article->published_at,
                'latest_source_host' => $article->source_host,
                'latest_source_url' => $article->url,
                'source_count' => 1,
                'update_count' => 1,
                'no_update_count' => 0,
                'fingerprint' => $this->resolver->fingerprint($article->original_title ?: $result->title),
            ]);
            $case->save();

            $article->forceFill([
                'case_id' => $case->id,
                'title' => $result->title,
                'title_pt' => $result->titlePt,
                'summary' => $result->summary,
                'summary_pt' => $result->summaryPt,
                'summary_with_context' => $result->summary,
                'summary_with_context_pt' => $result->summaryPt,
                'criticity' => $result->criticity,
                'indicators' => $result->indicators,
                'status' => 'enriched',
                'is_first' => true,
                'is_new_info' => true,
                'new_context' => null,
                'new_context_pt' => null,
                'enricher_driver' => $result->driver,
                'error' => null,
            ])->save();

            return $case;
        });

        // Antes de indexar e de alertar: a busca e o alerta já saem com o exploit.
        $this->linkExploits($case);
        ExplainVulnerability::dispatchFor($case);

        // Reanálise no lugar ($into) não é notícia nova: não alerta de novo.
        if (! $into) {
            MatchCaseToProfiles::dispatchFor($case, $article->id, 'new_case');
        }

        return $case;
    }

    public function appendUpdate(NewsCase $case, Article $article, EnrichmentResult $result): NewsCase
    {
        DB::transaction(function () use ($case, $article, $result) {
            $article->forceFill([
                'case_id' => $case->id,
                'title' => $result->title,
                'title_pt' => $result->titlePt,
                'summary' => $result->summary,
                'summary_pt' => $result->summaryPt,
                'summary_with_context' => $this->withContext($result->newContext, $result->summary),
                'summary_with_context_pt' => $this->withContext($result->newContextPt, $result->summaryPt),
                'new_context' => $result->newContext,
                'new_context_pt' => $result->newContextPt,
                'criticity' => $result->criticity,
                'indicators' => $result->indicators,
                'status' => 'enriched',
                'is_first' => false,
                'is_new_info' => true,
                'enricher_driver' => $result->driver,
                'error' => null,
            ])->save();

            $merged = $this->mergeIndicators($case->indicators ?? [], $result->indicators);

            // A IA enriqueceu o relatório do caso com o artigo. Sem esse
            // relatório (ex.: caiu para o heurístico), o atual fica como está:
            // a análise de um artigo só apagaria o que as outras fontes trouxeram.
            if ($result->caseSummary !== null) {
                $case->forceFill([
                    'title' => $result->caseTitle,
                    // Sem edição pt-BR nesta análise, a anterior ficaria defasada:
                    // melhor cair para o inglês atual.
                    'title_pt' => $result->caseTitlePt,
                    'summary' => $result->caseSummary,
                    'summary_pt' => $result->caseSummaryPt,
                    // A matéria da fusão continua consolidada, agora com esta fonte.
                    'consolidated_at' => $case->consolidated_at ? now() : null,
                ]);
            }

            $case->forceFill([
                'criticity' => $this->strongest($case->criticity, $result->criticity),
                'indicators' => $merged,
                'victim_sectors' => $this->valuesOf($merged, 'victim_sectors'),
                'threat_actors' => $this->valuesOf($merged, 'threat_actors'),
                'cves' => $this->valuesOf($merged, 'cves'),
                'latest_at' => $article->collected_at,
                'last_published_at' => $article->published_at ?: $case->last_published_at,
                'latest_source_host' => $article->source_host,
                'latest_source_url' => $article->url,
                'update_count' => $case->update_count + 1,
                'source_count' => $case->articles()->distinct()->count('source_host'),
            ])->save();
        });

        $this->linkExploits($case->refresh());
        ExplainVulnerability::dispatchFor($case);

        MatchCaseToProfiles::dispatchFor($case, $article->id, 'case_update');

        return $case;
    }

    /** Fonte repetiu o que já se sabia: registra a repetição sem poluir o feed. */
    public function markNoUpdate(NewsCase $case, Article $article, EnrichmentResult $result): void
    {
        DB::transaction(function () use ($case, $article, $result) {
            $article->forceFill([
                'case_id' => $case->id,
                'title' => $result->title,
                'title_pt' => $result->titlePt,
                'summary' => $result->summary,
                'summary_pt' => $result->summaryPt,
                'criticity' => $result->criticity,
                'indicators' => $result->indicators,
                'status' => 'no_update',
                'is_first' => false,
                'is_new_info' => false,
                'new_context' => null,
                'new_context_pt' => null,
                'enricher_driver' => $result->driver,
                // Como na matéria nova e na atualização: o erro da tentativa anterior
                // não é o desta (02/11, artigo 2419: o refazer deu certo como
                // repetição e o erro do claude ficou na tela).
                'error' => null,
            ])->save();

            $case->forceFill([
                'no_update_count' => $case->no_update_count + 1,
                'source_count' => $case->articles()->distinct()->count('source_host'),
            ])->save();
        });

        $this->index->put($case->refresh());
    }

    /** Liga os exploits dos CVEs, indexa e pede a explicação dos que entraram em destaque. */
    private function linkExploits(NewsCase $case): void
    {
        $added = $this->exploits->link($case);
        $this->index->put($case);

        if ($added !== []) {
            ExplainExploit::dispatchMissing($this->exploits->featured($case));
        }
    }

    private function withContext(?string $context, ?string $summary): ?string
    {
        if ($summary === null) {
            return null;
        }

        return trim(($context ? $context."\n\n" : '').$summary);
    }

    public function mergeIndicators(array $current, array $incoming): array
    {
        $byField = [];

        foreach ([$current, $incoming] as $set) {
            foreach ($set as $group) {
                $field = $group['field'] ?? null;

                if (! $field) {
                    continue;
                }

                $byField[$field] = array_merge($byField[$field] ?? [], $group['values'] ?? []);
            }
        }

        $merged = [];
        // CVE que uma fonte trata como assunto não fica também no contexto do caso.
        $main = array_flip(array_map(fn ($v) => mb_strtolower(trim((string) $v)), $byField['cves'] ?? []));

        foreach (config('secmon.indicator_fields') as $field => $label) {
            if (empty($byField[$field])) {
                continue;
            }

            // Preserva a primeira grafia vista de cada valor (case-insensitive).
            $seen = [];

            foreach ($byField[$field] as $value) {
                $key = mb_strtolower(trim((string) $value));

                if ($field === 'related_cves' && isset($main[$key])) {
                    continue;
                }

                if ($key !== '' && ! isset($seen[$key])) {
                    $seen[$key] = trim((string) $value);
                }
            }

            if ($seen !== []) {
                $merged[] = ['field' => $field, 'label' => $label, 'values' => array_values($seen)];
            }
        }

        return $merged;
    }

    private function valuesOf(array $groups, string $field): array
    {
        foreach ($groups as $group) {
            if (($group['field'] ?? null) === $field) {
                return $group['values'] ?? [];
            }
        }

        return [];
    }

    public function strongest(?string $a, ?string $b): ?string
    {
        $order = array_flip(config('secmon.criticities')); // critical = 0

        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return ($order[$b] ?? 99) < ($order[$a] ?? 99) ? $b : $a;
    }
}
