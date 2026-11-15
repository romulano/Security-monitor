<?php

namespace App\Services\Crawler;

use App\Models\Article;
use App\Models\ArticleAttempt;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Enrichment\ErrorReasons;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * O histórico das tentativas de análise de cada artigo e o que Admin → Coletas
 * diz de um artigo com erro: ainda não foi refeito, foi refeito e deu certo, ou
 * foi refeito e falhou de novo (quantas vezes).
 */
class AnalysisAttempts
{
    public function __construct(private ErrorReasons $reasons) {}

    /**
     * Grava uma tentativa. Sem resultado, a análise nem chegou ao fim (`erro`); com
     * erro do driver, entrou a heurística no lugar da IA.
     */
    public function record(Article $article, string $trigger, ?EnrichmentResult $result, ?string $error): void
    {
        $trigger = array_key_exists($trigger, ArticleAttempt::TRIGGERS) ? $trigger : 'coleta';
        $driver = mb_substr($error !== null || $result === null ? $this->driver() : $result->driver, 0, 16);

        if ($error !== null) {
            $this->reasons->queue($error, $driver, 'article analysis ('.ArticleAttempt::TRIGGERS[$trigger].')', (int) $article->content_chars);
        }

        ArticleAttempt::create([
            'article_id' => $article->id,
            'trigger' => $trigger,
            'driver' => $driver,
            'result' => match (true) {
                $result === null => 'erro',
                $error !== null || $result->driver === 'heuristic' => 'heuristica',
                default => 'ok',
            },
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
        ]);
    }

    /**
     * A fusão leu os artigos destas matérias e escreveu (ou não) a matéria nova. Fica
     * no histórico só de quem está com erro, para o admin ver que a fusão passou por
     * ali; a análise do próprio artigo continua a mesma.
     *
     * @param  list<int>  $caseIds
     */
    public function recordFusion(array $caseIds, ?string $error): void
    {
        if ($error !== null) {
            $this->reasons->queue($error, 'hermes', 'story merge (the story report, not the article analysis)', 0);
        }

        Article::query()->whereIn('case_id', $caseIds)->whereNotNull('error')->pluck('id')
            ->each(fn (int $id) => ArticleAttempt::create([
                'article_id' => $id,
                'trigger' => 'fusao',
                'driver' => 'hermes',
                'result' => $error === null ? 'ok' : 'erro',
                'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
            ]));
    }

    /**
     * O que a tela diz do artigo. Null: nada a dizer (sem erro e sem falha no
     * histórico). Espera `analysisAttempts` carregado.
     *
     * @return ?array{state: 'pending'|'failed_again'|'recovered', at: ?Carbon, failures: int, error: ?string}
     */
    public function situation(Article $article): ?array
    {
        $attempts = $article->analysisAttempts;
        $redos = $attempts->whereIn('trigger', ArticleAttempt::REDO)->values();
        $last = $redos->last();

        if ($article->error === null) {
            $failed = $attempts->filter->failed()->last();

            // Deu certo depois de uma falha: o erro some e vira histórico.
            return $failed && $last && ! $last->failed() && $last->id > $failed->id
                ? ['state' => 'recovered', 'at' => $last->created_at, 'failures' => 0, 'error' => $failed->error]
                : null;
        }

        if (! $last || ! $last->failed()) {
            return ['state' => 'pending', 'at' => null, 'failures' => 0, 'error' => $article->error];
        }

        return [
            'state' => 'failed_again',
            'at' => $last->created_at,
            'failures' => $redos->filter->failed()->count(),
            'error' => $article->error,
        ];
    }

    private function driver(): string
    {
        return Settings::bool('enricher.enabled') ? (string) Settings::get('enricher.driver') : 'heuristic';
    }
}
