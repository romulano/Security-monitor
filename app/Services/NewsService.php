<?php

namespace App\Services;

use App\Models\Article;
use App\Models\CaseMerge;
use App\Models\Exploit;
use App\Models\NewsCase;
use App\Services\Exploits\MainCves;
use App\Services\Search\CaseIndex;
use App\Support\QueryTokens;
use App\Support\ReportLanguage;
use App\Support\ReportSections;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Montagem das respostas do feed, da matéria, do "atividade recente" e do
 * dashboard — num formato só, para que a UI e qualquer integração externa
 * possam consumir sem tradução.
 */
class NewsService
{
    public function __construct(private CaseIndex $index) {}

    /**
     * $lang e $site só vêm do site. A API chama sem eles e recebe sempre o
     * mesmo formato: em inglês, sem os campos `_pt` e sem `has_exploit`.
     */
    public function feed(string $query, int $page, string $lang = ReportLanguage::EN, bool $site = false): array
    {
        // Mais filtros do que a busca aceita: a resposta sai sem consultar o Elasticsearch.
        $result = QueryTokens::filterCount($query) > QueryTokens::MAX_FILTERS
            ? CaseIndex::emptyResult($query, max(1, $page), (int) config('secmon.per_page'), self::tooManyFilters())
            : $this->index->search($query, $page, (int) config('secmon.per_page'));

        $result['cases'] = array_map(
            fn (array $case) => $this->localize($site ? $case : Arr::except($case, 'has_exploit'), $lang, ['title', 'summary']),
            $result['cases'],
        );

        return $result;
    }

    /** O aviso da busca que passou do teto de filtros (QueryTokens::MAX_FILTERS). */
    private static function tooManyFilters(): string
    {
        return 'Busca com filtros demais: use até '.QueryTokens::MAX_FILTERS.' filtros (campo:valor) por vez. Tire algum para ver os casos.';
    }

    /** A página do feed no site: os casos da busca e os filtros que ela já tem. */
    public function feedPage(string $query, int $page, string $lang): array
    {
        return [
            'query' => $query,
            'result' => $this->feed($query, $page, $lang, site: true),
            'chips' => QueryTokens::chips($query),
            'criticity' => QueryTokens::criticity($query),
            'lang' => $lang,
        ];
    }

    public function dashboard(int $windowDays = 90): array
    {
        return $this->index->dashboard($windowDays);
    }

    /**
     * Matéria apagada pela fusão de matérias: o uuid da matéria que juntou o
     * conteúdo dela, para o link antigo (de um alerta, de um favorito) levar até lá.
     */
    public function mergedInto(string $uuid): ?string
    {
        return CaseMerge::query()->where('uuid', $uuid)->with('newsCase:id,uuid')->first()?->newsCase?->uuid;
    }

    /**
     * Matéria que existe, mas saiu do feed público (fora de segurança): a
     * página dela dá 410 para quem não é admin, e não 404.
     */
    public function hidden(string $uuid): bool
    {
        return NewsCase::query()->where('uuid', $uuid)->where('is_published', false)->exists();
    }

    /** Últimas coletas, alimenta o painel "Atividade recente". */
    public function updates(int $limit = 8, string $lang = ReportLanguage::EN): array
    {
        $pt = $lang === ReportLanguage::PT;

        return Article::query()
            ->whereNotNull('case_id')
            ->whereIn('status', ['enriched', 'no_update'])
            ->whereHas('newsCase', fn ($q) => $q->where('is_published', true))
            ->orderByDesc('collected_at')
            ->limit(max(1, min(50, $limit)))
            ->with('newsCase:id,uuid,criticity')
            ->get()
            ->map(function (Article $a) use ($pt) {
                $articlePt = $pt && filled($a->summary_pt);

                return [
                    'uuid' => $a->newsCase?->uuid,
                    'title' => ($articlePt ? $a->title_pt : null) ?: $a->title ?: $a->original_title,
                    'source_host' => $a->source_host,
                    'criticity' => $a->criticity ?: $a->newsCase?->criticity,
                    'collected_at' => optional($a->collected_at)->toIso8601String(),
                    'is_first' => (bool) $a->is_first,
                    'new_context' => $articlePt ? $a->new_context_pt : $a->new_context,
                ];
            })
            ->all();
    }

    /**
     * `$withExtraction` só serve ao site: marca o que foi analisado a partir do
     * resumo do feed (a fonte não liberou a página) e traz os exploits
     * públicos dos CVEs. A API não recebe esses campos, porque o formato dela
     * é fixo, campo a campo. A matéria escondida do feed público só vem com
     * `$withHidden` (o admin, no site).
     */
    public function case(string $uuid, string $lang = ReportLanguage::EN, bool $withExtraction = false, bool $withHidden = false): ?array
    {
        $case = NewsCase::query()->where('uuid', $uuid)->unless($withHidden, fn ($q) => $q->where('is_published', true))->first();

        if (! $case) {
            return null;
        }

        $pt = $lang === ReportLanguage::PT;
        $articles = $case->articles()->orderByDesc('collected_at')->get();

        $timeline = $articles->where('status', '!=', 'no_update')->values();
        $noUpdate = $articles->where('status', 'no_update')->values();

        // Só o site pede pt-BR; aí a matéria diz em que idioma de fato saiu.
        $casePt = $pt && filled($case->summary_pt);
        $reportLang = $pt ? ['report_lang' => $casePt ? ReportLanguage::PT : ReportLanguage::EN] : [];

        $entry = fn (Article $a) => $this->entry($a, $pt)
            + ($withExtraction ? ['summary_only' => self::summaryOnly($a)] : []);

        // O caso inteiro saiu de resumos quando nenhuma fonte com informação
        // nova entregou o texto da matéria.
        $extraction = $withExtraction
            ? [
                'summary_only' => $timeline->isNotEmpty() && $timeline->every(fn (Article $a) => self::summaryOnly($a)),
                'exploits' => $this->exploits($case, $pt),
                'vulnerability' => $this->vulnerability($case, $pt),
                'consolidated' => $this->consolidated($case, $articles),
                // A página mostra cada parte do relatório no seu lugar, sem a linha do tempo dele.
                'report' => ReportSections::split($casePt ? $case->summary_pt : $case->summary),
                // O cartão de link (Open Graph) e a descrição da página: sempre em inglês, como a divulgação.
                'share' => ['title' => $case->title, 'summary' => ReportSections::split($case->summary)['body']],
                // Só o admin chega aqui com ela escondida: o aviso e o botão de devolver ao feed.
                'hidden' => $case->isHidden()
                    ? ['at' => $case->hidden_at?->toIso8601String(), 'reason' => $case->hiddenLabel()]
                    : null,
            ]
            : [];

        return $reportLang + $extraction + [
            'uuid' => $case->uuid,
            'title' => ($casePt ? $case->title_pt : null) ?: $case->title,
            'criticity' => $case->criticity,
            'victim_sectors' => $case->victim_sectors ?? [],
            'latest_summary' => $casePt ? $case->summary_pt : $case->summary,
            'indicators' => $case->indicators ?? [],
            'stats' => [
                'first_collected_at' => optional($case->first_at)->toIso8601String(),
                'last_collected_at' => optional($case->latest_at)->toIso8601String(),
                'first_published_at' => optional($case->first_published_at)->toIso8601String(),
                'last_published_at' => optional($case->last_published_at)->toIso8601String(),
                'source_count' => (int) $case->source_count,
                'total_docs' => $articles->count(),
                'new_info_count' => $timeline->count(),
                'no_update_count' => $noUpdate->count(),
            ],
            'timeline' => $timeline->map($entry)->all(),
            'no_update_sources' => $noUpdate->map($entry)->all(),
        ];
    }

    /**
     * Exploits públicos dos CVEs principais do caso, na ordem de `Exploit::showcase`
     * (os que a IA pôs em dúvida por último, depois os mais recentes primeiro).
     * A explicação é a da IA no idioma pedido, ou nula enquanto não foi escrita
     * (a matéria mostra então só os dados do índice).
     */
    private function exploits(NewsCase $case, bool $pt): array
    {
        $exploits = $case->exploits()->showcase()->get();

        if ($exploits->isEmpty()) {
            return [];
        }

        $cves = DB::table('exploit_cve')
            ->whereIn('exploit_id', $exploits->modelKeys())
            ->whereIn('cve', app(MainCves::class)->of($case))
            ->orderBy('cve')
            ->get()
            ->groupBy('exploit_id');

        return $exploits->map(fn (Exploit $e) => [
            'edb_id' => $e->edbId(),
            'title' => $e->description,
            'type' => $e->type,
            'platform' => $e->platform,
            'author' => $e->author,
            'verified' => $e->verified,
            'credible' => $e->credible !== false,
            'published_at' => $e->published_on?->toDateString(),
            'found_at' => $e->pivot->created_at ? Carbon::parse($e->pivot->created_at)->toIso8601String() : null,
            'url' => $e->url(),
            'cves' => ($cves[$e->id] ?? collect())->pluck('cve')->all(),
            'explanation' => ($pt ? $e->explanation_pt : null) ?: $e->explanation,
        ])->all();
    }

    /**
     * O que a IA explicou da falha e de como ela foi descoberta, no idioma
     * pedido. Nulo quando a matéria não é de vulnerabilidade ou ainda não foi explicada.
     *
     * @return array{flaw: string, process: ?string, cause: ?string, location: ?string, poc: ?string, poc_private: bool, poc_url: ?string, discovery: ?string, at: ?string}|null
     */
    private function vulnerability(NewsCase $case, bool $pt): ?array
    {
        if (! $case->vulnerability_applies || blank($case->vulnerability_explanation)) {
            return null;
        }

        $detail = fn (string $key) => (($pt ? ($case->vulnerability_details[$key]['pt'] ?? null) : null) ?: ($case->vulnerability_details[$key]['en'] ?? null)) ?: null;

        return [
            'flaw' => ($pt ? $case->vulnerability_explanation_pt : null) ?: $case->vulnerability_explanation,
            'process' => $detail('process'),
            'cause' => $detail('cause'),
            'location' => $detail('location'),
            'poc' => $detail('poc'),
            // Sem a marca (as explicações de antes dela), é pública: o pedido só aceitava PoC publicada.
            'poc_private' => ($case->vulnerability_details['poc_access'] ?? null) === 'private',
            'poc_url' => ($case->vulnerability_details['poc_url'] ?? null) ?: null,
            'discovery' => (($pt ? $case->discovery_explanation_pt : null) ?: $case->discovery_explanation) ?: null,
            'at' => $case->vulnerability_explained_at?->toIso8601String(),
        ];
    }

    /**
     * Matéria escrita pela fusão de matérias: quantas matérias ela juntou e as
     * fontes de todas, uma vez cada (o link é o artigo mais recente da fonte).
     *
     * @param  Collection<int, Article>  $articles  do mais novo para o mais antigo
     */
    private function consolidated(NewsCase $case, Collection $articles): ?array
    {
        if (! $case->consolidated_at) {
            return null;
        }

        return [
            'at' => $case->consolidated_at->toIso8601String(),
            'stories' => $case->merges()->count(),
            'sources' => $articles
                ->filter(fn (Article $a) => filled($a->source_host))
                ->groupBy('source_host')
                ->map(fn (Collection $items, string $host) => ['host' => $host, 'url' => $items->first()->url, 'count' => $items->count()])
                ->values()
                ->all(),
        ];
    }

    /** O crawler não leu a página da matéria: a análise saiu só do resumo do feed. */
    private static function summaryOnly(Article $a): bool
    {
        return in_array($a->extraction, ['summary', 'empty'], true);
    }

    private function entry(Article $a, bool $pt = false): array
    {
        // Artigo sem edição pt-BR cai inteiro para o inglês, para não misturar
        // resumo num idioma com contexto no outro.
        $pt = $pt && filled($a->summary_pt);

        return [
            'collected_at' => optional($a->collected_at)->toIso8601String(),
            'published_at' => optional($a->published_at)->toIso8601String(),
            'feed_url' => $a->feed_url,
            'news_url' => $a->url,
            'source_host' => $a->source_host,
            'title' => ($pt ? $a->title_pt : null) ?: $a->title ?: $a->original_title,
            'summary' => $pt ? $a->summary_pt : $a->summary,
            'summary_with_context' => $pt
                ? ($a->summary_with_context_pt ?: $a->summary_pt)
                : ($a->summary_with_context ?: $a->summary),
            'new_context' => $pt ? $a->new_context_pt : $a->new_context,
            'indicators' => $a->indicators ?? [],
        ];
    }

    /**
     * Troca cada campo pela edição pt-BR quando ela foi pedida e existe, e
     * sempre remove as variantes `_pt` — fora do site a resposta sai em inglês.
     */
    private function localize(array $item, string $lang, array $fields): array
    {
        $translated = $lang === ReportLanguage::PT && filled($item['summary_pt'] ?? null);

        foreach ($fields as $field) {
            if ($translated && filled($item[$field.'_pt'] ?? null)) {
                $item[$field] = $item[$field.'_pt'];
            }

            unset($item[$field.'_pt']);
        }

        if ($lang === ReportLanguage::PT) {
            $item['report_lang'] = $translated ? ReportLanguage::PT : ReportLanguage::EN;
        }

        return $item;
    }
}
