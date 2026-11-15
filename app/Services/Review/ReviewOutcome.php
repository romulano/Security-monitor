<?php

namespace App\Services\Review;

use App\Models\Article;
use App\Models\NewsCase;
use App\Models\ReportReview;

/**
 * Como terminou a revisão de uma versão do relatório: aprovada, com revisão
 * pendente ou pulada, com os pareceres e o motivo. Gravada depois que a
 * matéria é escrita, quando ela já tem id.
 */
final class ReviewOutcome
{
    /** @param  list<array{verdict: string, scope: ?string, problems: list<array<string, mixed>>, note: string}>  $rounds */
    public function __construct(
        public readonly string $status,
        public readonly string $mode,
        public readonly array $rounds = [],
        public readonly int $adjustments = 0,
        public readonly int $calls = 0,
        public readonly ?string $reason = null,
        public readonly int $seconds = 0,
    ) {}

    public static function skipped(string $mode, string $reason): self
    {
        return new self(ReportReview::SKIPPED, $mode, reason: $reason);
    }

    /** @param  string  $origin  `coleta` (artigo novo) ou `refazer` (reanálise da matéria) */
    public function record(?NewsCase $case, Article $article, string $origin = 'coleta'): ReportReview
    {
        return ReportReview::query()->create([
            'case_id' => $case?->id,
            'article_id' => $article->id,
            'origin' => $origin,
            'mode' => $this->mode,
            'status' => $this->status,
            'adjustments' => $this->adjustments,
            'calls' => $this->calls,
            'rounds' => $this->rounds,
            'reason' => $this->reason !== null ? mb_substr($this->reason, 0, 300) : null,
            'seconds' => $this->seconds,
        ]);
    }
}
