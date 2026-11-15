<?php

namespace App\Services\Admin;

use App\Models\NewsCase;
use App\Models\ReportReview;
use App\Services\Review\ReportReviewer;
use App\Support\Prompts;
use Illuminate\Support\Carbon;

/**
 * O revisor do relatório para o admin: na página da matéria, a revisão da
 * versão no ar (e as anteriores); no "IA agora" de Coletas, o que ele fez no
 * período e as matérias que ficaram com a revisão pendente.
 *
 * A marca "revisão pendente" é só do admin: o leitor vê a matéria como ela
 * saiu. O revisor também erra, e o aviso sem o que corrigir só tiraria a
 * confiança na matéria; quem decide (refazer, editar, tirar do feed) é o admin.
 */
class ReportReviewReport
{
    /** Versões anteriores mostradas na página da matéria. */
    private const HISTORY = 4;

    /** Matérias com a revisão pendente listadas no "IA agora". */
    private const OPEN = 5;

    public const STATUS = [
        ReportReview::APPROVED => 'Aprovada pelo revisor',
        ReportReview::PENDING => 'Revisão pendente',
        ReportReview::SKIPPED => 'Revisão pulada',
    ];

    /** @return ?array{current: array<string, mixed>, history: list<array<string, mixed>>} */
    public function forCase(string $uuid): ?array
    {
        $caseId = NewsCase::query()->where('uuid', $uuid)->value('id');
        $reviews = $caseId ? ReportReview::query()->where('case_id', $caseId)->latest('id')->limit(self::HISTORY + 1)->get() : collect();

        if ($reviews->isEmpty()) {
            return null;
        }

        return [
            'current' => self::row($reviews->first()),
            'history' => $reviews->skip(1)->map(self::row(...))->values()->all(),
        ];
    }

    /**
     * @return array{on: bool, approved: int, adjusted: int, pending: int, skipped: int, calls: int, open_total: int, open: list<array{uuid: string, title: string, at: Carbon}>}
     */
    public function summary(Carbon $since): array
    {
        $period = ReportReview::query()->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as total, sum(calls) as calls, sum(case when adjustments > 0 then 1 else 0 end) as adjusted')
            ->groupBy('status')->get()->keyBy('status');

        // Pendente é a revisão em vigor da matéria: uma versão nova aprovada tira a marca.
        $open = ReportReview::query()->latestPerCase()->where('status', ReportReview::PENDING);

        return [
            'on' => Prompts::stageOn(ReportReviewer::STAGE),
            'approved' => (int) ($period->get(ReportReview::APPROVED)?->total ?? 0),
            'adjusted' => (int) ($period->get(ReportReview::APPROVED)?->adjusted ?? 0),
            'pending' => (int) ($period->get(ReportReview::PENDING)?->total ?? 0),
            'skipped' => (int) ($period->get(ReportReview::SKIPPED)?->total ?? 0),
            'calls' => (int) $period->sum('calls'),
            'open_total' => (clone $open)->count(),
            'open' => $open->with('newsCase:id,uuid,title')->latest('id')->limit(self::OPEN)->get()
                ->filter(fn (ReportReview $review) => $review->newsCase)
                ->map(fn (ReportReview $review) => ['uuid' => $review->newsCase->uuid, 'title' => (string) $review->newsCase->title, 'at' => $review->created_at])
                ->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function row(ReportReview $review): array
    {
        return [
            'status' => $review->status,
            'label' => self::STATUS[$review->status] ?? $review->status,
            'at' => $review->created_at,
            'origin' => $review->origin === 'refazer' ? 'no refazer com a IA' : 'na coleta',
            'mode' => $review->mode === 'update' ? 'atualização' : 'matéria nova',
            'adjustments' => $review->adjustments,
            'calls' => $review->calls,
            'reason' => $review->reason,
            'rounds' => $review->rounds ?? [],
            'problems' => $review->lastProblems(),
        ];
    }
}
