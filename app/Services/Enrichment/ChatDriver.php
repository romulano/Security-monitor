<?php

namespace App\Services\Enrichment;

use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Review\ReviewVerdict;

/**
 * O driver de IA que também fala com o revisor do relatório: além da análise,
 * ele responde a uma pergunta de uma vez (`chat`, o parecer) e refaz o rascunho
 * que o revisor reprovou (`revise`).
 *
 * Quem escreveu o rascunho o revisa: o parecer e a refação vão para a mesma IA
 * da análise, seja o hermes ou o OpenRouter — a revisão não depende mais de o
 * hermes estar no ar. O driver que não fala com o revisor (a extração
 * heurística) tem a revisão pulada e registrada.
 */
interface ChatDriver extends Enricher
{
    /** Uma pergunta só, com a resposta em texto; `$timeout` é o tempo que a IA recebe. */
    public function chat(string $system, string $user, int $timeout): string;

    /** Segundos que a IA recebe em cada chamada deste driver. */
    public function chatTimeout(): int;

    /** Segundos a reservar além do `chatTimeout()` (folga da requisição e as tentativas). */
    public function chatReserve(): int;

    /** O rascunho refeito com o pedido do revisor. */
    public function revise(Article $article, ?NewsCase $existing, EnrichmentResult $draft, string $mode, ReviewVerdict $verdict): EnrichmentResult;
}
