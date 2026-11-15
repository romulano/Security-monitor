<?php

namespace App\Services\Admin;

use App\Models\Article;
use App\Models\CrawlRun;
use App\Models\Source;
use App\Services\Crawler\AnalysisAttempts;
use App\Services\Enrichment\ErrorReasons;
use Illuminate\Support\Carbon;

/**
 * Painel de coletas: o que os bots publicaram nas últimas horas, em ordem de
 * chegada, com a saúde de cada fonte ao lado, e o que a IA está fazendo agora
 * (com o revisor do relatório).
 */
class CollectionsReport
{
    public function __construct(
        private ArticleManager $articles,
        private QueueMonitor $queue,
        private ReportReviewReport $reviews,
        private AnalysisAttempts $attempts,
        private ErrorReasons $reasons,
    ) {}

    /** @param  array{source?: ?string, status?: ?string, extraction?: ?string, window?: ?string}  $filters */
    public function page(array $filters): array
    {
        $hours = (int) ($filters['window'] ?? 24);
        $since = now()->subHours($hours);

        $articles = Article::query()
            ->with(['newsCase:id,uuid,criticity,title,is_published', 'source:id,name,host', 'analysisAttempts'])
            ->when($filters['source'] ?? null, fn ($q, $host) => $q->where('source_host', $host))
            // "IA falhou": viraram matéria pela heurística (ArticleManager::withoutAi).
            // "fora do feed": a matéria saiu do feed público; a página dela leva ao botão de devolver.
            ->when($filters['status'] ?? null, fn ($q, $status) => match ($status) {
                'sem_ia' => $q->whereIn('id', $this->articles->withoutAi()->select('id')),
                'fora_do_feed' => $q->whereHas('newsCase', fn ($q) => $q->where('is_published', false)),
                default => $q->where('status', $status),
            })
            // "só resumo do feed" inclui o feed que não trouxe nem resumo (empty).
            ->when($filters['extraction'] ?? null, fn ($q, $e) => $e === 'summary'
                ? $q->whereIn('extraction', ['summary', 'empty'])
                : $q->where('extraction', $e))
            ->latest('collected_at')
            ->paginate(60)
            ->withQueryString();

        // Artigo com erro: ainda não refeito, refeito e falhou de novo, ou refeito e deu
        // certo; com o motivo em linguagem clara (mapa fixo ou explicação do hermes).
        $situations = $articles->getCollection()
            ->mapWithKeys(fn (Article $article) => [$article->id => $this->attempts->situation($article)])
            ->filter();
        $reasons = $this->reasons->reasons($situations->pluck('error')->filter()->unique());

        return [
            'articles' => $articles,
            'situations' => $situations
                ->map(fn (array $situation) => [...$situation, 'reason' => $reasons[$situation['error']] ?? null])
                ->all(),
            'filters' => $filters,
            'hours' => $hours,
            'counters' => $this->counters($since),
            'ai' => $this->queue->aiNow($since),
            'review' => $this->reviews->summary($since),
            'sources' => $this->sourceHealth(),
            'lastRun' => CrawlRun::query()->latest('started_at')->first(),
        ];
    }

    private function counters(Carbon $since): array
    {
        $window = Article::query()->where('collected_at', '>=', $since);

        return [
            'coletados' => (clone $window)->count(),
            'materias' => (clone $window)->where('is_first', true)->count(),
            'atualizacoes' => (clone $window)->where('status', 'enriched')->where('is_first', false)->count(),
            'repeticoes' => (clone $window)->where('status', 'no_update')->count(),
            'pendentes' => Article::query()->whereIn('status', ['pending', 'failed'])->count(),
            // Em qualquer época, não só na janela: é o que o botão "Refazer com a IA" refaz.
            'sem_ia' => $this->articles->withoutAi()->count(),
            'sem_ia_motivos' => $this->articles->withoutAiByReason()
                ->each(fn ($row) => $row->clear = $this->reasons->known((string) $row->reason)),
            'ultima_coleta' => Article::query()->max('collected_at'),
            'corpo_completo' => (clone $window)->where('extraction', 'full')->count(),
            'so_resumo' => (clone $window)->whereIn('extraction', ['summary', 'empty'])->count(),
        ];
    }

    /**
     * Saúde por fonte: última coleta, volume e quantas matérias vieram com o
     * corpo do site (contra as que só tiveram o resumo do feed).
     */
    private function sourceHealth(): array
    {
        $stats = Article::query()
            ->selectRaw('source_host')
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where extraction = 'full') as full_body")
            ->selectRaw('max(collected_at) as last_at')
            ->whereNotNull('source_host')
            ->groupBy('source_host')
            ->get()
            ->keyBy('source_host');

        return Source::query()
            ->orderByDesc('enabled')
            ->orderBy('host')
            ->get()
            ->map(function (Source $source) use ($stats) {
                $row = $stats->get($source->host);
                $total = (int) ($row->total ?? 0);
                $full = (int) ($row->full_body ?? 0);

                return [
                    'source' => $source,
                    'total' => $total,
                    'full' => $full,
                    'rate' => $total > 0 ? (int) round($full / $total * 100) : null,
                    'last_at' => $row->last_at ?? null,
                ];
            })
            ->all();
    }
}
