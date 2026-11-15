<?php

namespace App\Services\Admin;

use App\Models\Article;
use App\Models\CrawlRun;
use App\Models\NewsCase;
use App\Models\Source;
use App\Services\Enrichment\EnricherManager;
use App\Services\Search\CaseIndex;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/** Admin → Painel: números da base, saúde dos serviços e as últimas coletas. */
class AdminDashboard
{
    public function __construct(
        private CaseIndex $index,
        private EnricherManager $enrichers,
        private SecurityOverview $security,
    ) {}

    public function page(): array
    {
        $driver = $this->enrichers->driver();

        return [
            'stats' => [
                'cases' => NewsCase::query()->count(),
                'hidden' => NewsCase::query()->where('is_published', false)->count(),
                'articles' => Article::query()->count(),
                'pending' => Article::query()->whereIn('status', ['pending', 'failed'])->count(),
                'no_update' => Article::query()->where('status', 'no_update')->count(),
                'sources_on' => Source::query()->where('enabled', true)->count(),
                'sources_total' => Source::query()->count(),
                'indexed' => $this->index->count(),
                'queued' => DB::table('jobs')->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
            ],
            'health' => [
                'crawler' => Settings::bool('crawler.enabled'),
                'enricher' => Settings::bool('enricher.enabled'),
                'driver' => $driver->name(),
                'driver_ready' => $driver->isReady(),
                'elasticsearch' => $this->index->ping(),
            ],
            'security' => $this->security->card(),
            'runs' => CrawlRun::query()->with('source')->latest('started_at')->limit(15)->get(),
            'byCriticity' => NewsCase::query()
                ->selectRaw('criticity, count(*) as total')
                ->groupBy('criticity')
                ->pluck('total', 'criticity'),
        ];
    }
}
