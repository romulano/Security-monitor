<?php

namespace App\Services\Merge;

use App\Jobs\ExplainExploit;
use App\Jobs\ExplainVulnerability;
use App\Models\AlertMatch;
use App\Models\Article;
use App\Models\CaseMerge;
use App\Models\NewsCase;
use App\Services\Crawler\CaseResolver;
use App\Services\Crawler\CaseWriter;
use App\Services\Exploits\ExploitLinker;
use App\Services\Search\CaseIndex;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Grava a fusão: cria a matéria nova com o relatório da IA, leva para ela as
 * fontes, os alertas (com os votos dos clientes) e os exploits das antigas,
 * apaga as antigas e deixa o uuid de cada uma redirecionando para a nova.
 *
 * Não alerta: a fusão não traz notícia, só junta o que já foi publicado.
 */
class CaseMerger
{
    /** Colunas da explicação da falha que a matéria nova herda. */
    private const EXPLANATION = [
        'vulnerability_applies',
        'vulnerability_explanation',
        'vulnerability_explanation_pt',
        'vulnerability_details',
        'discovery_explanation',
        'discovery_explanation_pt',
        'vulnerability_explained_at',
    ];

    public function __construct(
        private CaseIndex $index,
        private CaseResolver $resolver,
        private CaseWriter $writer,
        private ExploitLinker $exploits,
    ) {}

    /**
     * Análises das fontes que a IA lê para escrever a matéria (sem as
     * repetições), da mais antiga para a mais nova. Com fontes demais, fica o
     * começo da história e o mais recente.
     *
     * @param  list<int>  $caseIds
     * @return Collection<int, Article>
     */
    public function sources(array $caseIds): Collection
    {
        return $this->cap(Article::query()
            ->whereIn('case_id', $caseIds)
            ->where('status', 'enriched')
            ->orderByRaw('coalesce(published_at, collected_at)')
            ->orderBy('id')
            ->get());
    }

    /**
     * A matéria consolidada cujo relatório a IA enriquece, em vez de escrever
     * do zero: a que reúne mais fontes. Nula no primeiro agrupamento.
     *
     * @param  Collection<int, NewsCase>  $cases
     */
    public function base(Collection $cases): ?NewsCase
    {
        return $cases
            ->filter(fn (NewsCase $case) => $case->consolidated_at !== null)
            ->sortByDesc(fn (NewsCase $case) => [$case->articles()->count(), $case->consolidated_at->getTimestamp()])
            ->first();
    }

    /**
     * Fontes que os relatórios consolidados ainda não leram: de matéria
     * consolidada, só as que chegaram depois do relatório; das outras, todas.
     *
     * @param  Collection<int, NewsCase>  $cases
     * @return Collection<int, Article>
     */
    public function unread(Collection $cases): Collection
    {
        return $this->cap(Article::query()
            ->where('status', 'enriched')
            ->where(function ($query) use ($cases) {
                foreach ($cases as $case) {
                    $query->orWhere(fn ($q) => $q->where('case_id', $case->id)
                        ->when($case->consolidated_at, fn ($q, $at) => $q->where('collected_at', '>', $at)));
                }
            })
            ->orderByRaw('coalesce(published_at, collected_at)')
            ->orderBy('id')
            ->get());
    }

    /**
     * Com fontes demais, fica o começo da história e o mais recente.
     *
     * @param  Collection<int, Article>  $articles
     * @return Collection<int, Article>
     */
    private function cap(Collection $articles): Collection
    {
        $max = (int) config('secmon.merge.max_sources');

        if ($articles->count() <= $max) {
            return $articles;
        }

        $head = intdiv($max, 4);

        return $articles->take($head)->concat($articles->take(-($max - $head)))->values();
    }

    /**
     * @param  list<int>  $caseIds
     * @param  array{title: string, summary: string, title_pt: ?string, summary_pt: ?string}  $report
     * @return NewsCase|null a matéria nova; nula quando sobrou menos de duas para juntar
     */
    public function merge(array $caseIds, array $report, string $reason = ''): ?NewsCase
    {
        $done = DB::transaction(function () use ($caseIds, $report, $reason) {
            // A trava segura a coleta: o artigo que chegar para uma destas
            // matérias espera a fusão e depois acha a nova pelo redirecionamento.
            $cases = NewsCase::query()
                ->whereIn('id', $caseIds)
                ->where('is_published', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($cases->count() < 2) {
                return null;
            }

            $ids = $cases->modelKeys();
            $articles = Article::query()->whereIn('case_id', $ids)->orderBy('collected_at')->orderBy('id')->get();
            $timeline = $articles->where('status', '!=', 'no_update');
            $latest = $articles->sortByDesc(fn (Article $a) => [$a->collected_at?->getTimestamp() ?? 0, $a->id])->first();

            $indicators = $cases->reduce(fn (array $carry, NewsCase $case) => $this->writer->mergeIndicators($carry, $case->indicators ?? []), []);
            $criticity = $cases->reduce(fn (?string $carry, NewsCase $case) => $this->writer->strongest($carry, $case->criticity));

            $case = (new NewsCase)->forceFill([
                'title' => $report['title'],
                'title_pt' => $report['title_pt'],
                'summary' => $report['summary'],
                'summary_pt' => $report['summary_pt'],
                'criticity' => $criticity,
                'indicators' => $indicators,
                'first_at' => $cases->min('first_at'),
                'latest_at' => $cases->max('latest_at'),
                'first_published_at' => $cases->min('first_published_at'),
                'last_published_at' => $cases->max('last_published_at'),
                'latest_source_host' => $latest?->source_host,
                'latest_source_url' => $latest?->url,
                'source_count' => $articles->pluck('source_host')->filter()->unique()->count(),
                'update_count' => $timeline->count(),
                'no_update_count' => $articles->count() - $timeline->count(),
                'fingerprint' => $this->resolver->fingerprint($report['title']),
                'consolidated_at' => now(),
            ]);

            // A explicação da falha vem da antiga explicada por último; depois
            // da fusão, a IA a atualiza com as fontes de todas.
            $explained = $cases
                ->filter(fn (NewsCase $old) => $old->vulnerability_applies && filled($old->vulnerability_explanation))
                ->sortByDesc(fn (NewsCase $old) => $old->vulnerability_explained_at?->getTimestamp() ?? 0)
                ->first();

            $case->forceFill([
                'victim_sectors' => $case->indicatorValues('victim_sectors'),
                'threat_actors' => $case->indicatorValues('threat_actors'),
                'cves' => $case->indicatorValues('cves'),
                ...($explained?->only(self::EXPLANATION) ?? []),
            ])->save();

            $snapshots = $this->snapshots($cases, $articles);

            Article::query()->whereIn('id', $articles->modelKeys())->update(['case_id' => $case->id, 'is_first' => false]);

            if ($first = $timeline->first() ?? $articles->first()) {
                Article::query()->whereKey($first->id)->update(['is_first' => true]);
            }

            // O voto do cliente e o "já recebeu" do juiz continuam valendo na nova.
            AlertMatch::query()->whereIn('case_id', $ids)->update(['case_id' => $case->id]);

            // O exploit fica com a data em que o Security Monitor o achou pela primeira vez.
            DB::statement(
                'insert into case_exploit (case_id, exploit_id, created_at)
                 select ?, exploit_id, min(created_at) from case_exploit where case_id in ('.implode(',', array_fill(0, count($ids), '?')).')
                 group by exploit_id on conflict do nothing',
                [$case->id, ...$ids],
            );

            // Fusão de matéria que já era fusão: os links mais antigos pulam direto para a nova.
            CaseMerge::query()->whereIn('case_id', $ids)->update(['case_id' => $case->id]);

            foreach ($cases as $old) {
                CaseMerge::create([
                    'uuid' => $old->uuid,
                    'case_id' => $case->id,
                    'title' => mb_substr((string) $old->title, 0, 255),
                    'fingerprint' => $old->fingerprint,
                    'cves' => $old->cves ?? [],
                    'reason' => $reason !== '' ? mb_substr($reason, 0, 500) : null,
                    'snapshot' => $snapshots[$old->id],
                    'merged_at' => now(),
                ]);
            }

            NewsCase::query()->whereIn('id', $ids)->delete();

            return [$case, $cases->pluck('uuid')->all()];
        });

        if (! $done) {
            return null;
        }

        [$case, $uuids] = $done;

        foreach ($uuids as $uuid) {
            $this->index->forget($uuid);
        }

        $added = $this->exploits->link($case);
        $this->index->put($case);

        if ($added !== []) {
            ExplainExploit::dispatchMissing($this->exploits->featured($case));
        }

        ExplainVulnerability::dispatchFor($case);

        Log::info('Fusão de matérias: matérias juntadas', ['nova' => $case->uuid, 'antigas' => $uuids, 'motivo' => $reason]);

        return $case;
    }

    /**
     * Matéria já consolidada que recebeu fontes depois: a IA enriquece o
     * relatório com elas, no mesmo link.
     *
     * @param  array{title: string, summary: string, title_pt: ?string, summary_pt: ?string}  $report
     */
    public function rewrite(NewsCase $case, array $report): void
    {
        $case->forceFill([
            'title' => $report['title'],
            'title_pt' => $report['title_pt'],
            'summary' => $report['summary'],
            'summary_pt' => $report['summary_pt'],
            'consolidated_at' => now(),
        ])->save();

        $this->index->put($case);
        ExplainVulnerability::dispatchFor($case);
    }

    /**
     * Matérias da fusão cujo relatório da IA ficou para trás: receberam fonte
     * nova (com informação) depois dele, ou foram refeitas pela reanálise.
     *
     * @return list<int>
     */
    public function outdated(): array
    {
        return NewsCase::query()
            ->where('is_published', true)
            ->whereHas('merges')
            ->where(fn ($q) => $q->whereNull('consolidated_at')->orWhereExists(fn ($q) => $q->selectRaw('1')
                ->from('articles')
                ->whereColumn('articles.case_id', 'cases.id')
                ->where('articles.status', 'enriched')
                ->whereColumn('articles.collected_at', '>', 'cases.consolidated_at')))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * Como cada matéria era antes da fusão, com o que saiu dela: dá para
     * conferir ou desfazer à mão.
     *
     * @param  Collection<int, NewsCase>  $cases
     * @param  Collection<int, Article>  $articles
     * @return array<int, array<string, mixed>>
     */
    private function snapshots(Collection $cases, Collection $articles): array
    {
        $ids = $cases->modelKeys();
        $matches = AlertMatch::query()->whereIn('case_id', $ids)->get(['id', 'case_id'])->groupBy('case_id');
        $exploits = DB::table('case_exploit')->whereIn('case_id', $ids)->get()->groupBy('case_id');
        $byCase = $articles->groupBy('case_id');

        return $cases->mapWithKeys(fn (NewsCase $case) => [$case->id => [
            'case' => collect($case->attributesToArray())->except(['indexed_at'])->all(),
            'articles' => $byCase->get($case->id, collect())->map(fn (Article $a) => ['id' => $a->id, 'is_first' => (bool) $a->is_first])->values()->all(),
            'alert_matches' => $matches->get($case->id, collect())->pluck('id')->all(),
            'exploits' => $exploits->get($case->id, collect())->map(fn ($row) => ['id' => $row->exploit_id, 'created_at' => $row->created_at])->values()->all(),
        ]])->all();
    }
}
