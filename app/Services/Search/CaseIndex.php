<?php

namespace App\Services\Search;

use App\Models\NewsCase;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Camada de busca. O índice do Elasticsearch é o que responde ao feed, às
 * facetas e ao dashboard; o PostgreSQL continua sendo a fonte de verdade.
 *
 * Os campos de indicador são `text` com analisador keyword+lowercase e um
 * sub-campo `.raw` keyword. Isso dá, ao mesmo tempo:
 *   - `threat_actors:"Scattered Spider"` casando sem depender de caixa;
 *   - `cves:CVE-2026-*` funcionando (o token não é quebrado no hífen);
 *   - facetas com o valor original preservado (`.raw`).
 */
class CaseIndex
{
    private ?Client $client = null;

    public function client(): Client
    {
        if ($this->client) {
            return $this->client;
        }

        $builder = ClientBuilder::create()
            ->setHosts([config('secmon.elasticsearch.host')])
            ->setRetries(2);

        $user = config('secmon.elasticsearch.username');
        $pass = config('secmon.elasticsearch.password');

        if ($user) {
            $builder->setBasicAuthentication($user, (string) $pass);
        }

        return $this->client = $builder->build();
    }

    public function index(): string
    {
        return (string) config('secmon.elasticsearch.index');
    }

    public function ping(): bool
    {
        try {
            return $this->client()->ping()->asBool();
        } catch (\Throwable) {
            return false;
        }
    }

    public function exists(): bool
    {
        try {
            return $this->client()->indices()->exists(['index' => $this->index()])->asBool();
        } catch (\Throwable) {
            return false;
        }
    }

    public function drop(): void
    {
        if ($this->exists()) {
            $this->client()->indices()->delete(['index' => $this->index()]);
        }
    }

    public function create(): void
    {
        if ($this->exists()) {
            return;
        }

        $this->client()->indices()->create([
            'index' => $this->index(),
            'body' => [
                'settings' => $this->settings(),
                'mappings' => ['properties' => $this->properties()],
            ],
        ]);
    }

    /**
     * Mapping dos casos. Público porque o índice de perfis (percolator) precisa
     * dos mesmos campos e analisadores para avaliar um caso como o feed avalia.
     */
    public function properties(): array
    {
        $indicatorField = [
            'type' => 'text',
            'analyzer' => 'lc_keyword',
            'search_analyzer' => 'lc_keyword',
            'copy_to' => 'all',
            'fields' => ['raw' => ['type' => 'keyword', 'ignore_above' => 512]],
        ];

        $properties = [
            'uuid' => ['type' => 'keyword'],
            'db_id' => ['type' => 'long'],
            'title' => [
                'type' => 'text',
                'copy_to' => 'all',
                'fields' => ['raw' => ['type' => 'keyword', 'ignore_above' => 512]],
            ],
            'summary' => ['type' => 'text', 'copy_to' => 'all'],
            // Edição pt-BR: entra no `all` para a busca achar termos em português.
            'title_pt' => ['type' => 'text', 'copy_to' => 'all'],
            'summary_pt' => ['type' => 'text', 'copy_to' => 'all'],
            'all' => ['type' => 'text'],
            'criticity' => ['type' => 'keyword'],
            'latest_source_host' => $indicatorField,
            'latest_source_url' => ['type' => 'keyword', 'index' => false],
            'source_hosts' => $indicatorField,
            'first_at' => ['type' => 'date'],
            'latest_at' => ['type' => 'date'],
            'source_count' => ['type' => 'integer'],
            'update_count' => ['type' => 'integer'],
            // Exploits públicos (Exploit-DB) dos CVEs do caso: `has_exploit:true`
            // filtra, `exploits:EDB-52473` acha o caso de um exploit.
            'has_exploit' => ['type' => 'boolean'],
            'exploits' => $indicatorField,
        ];

        foreach (array_keys(config('secmon.indicator_fields')) as $field) {
            $properties[$field] = $indicatorField;
        }

        return $properties;
    }

    public function settings(): array
    {
        return [
            'number_of_shards' => 1,
            'number_of_replicas' => 0,
            'refresh_interval' => '5s',
            'analysis' => [
                'analyzer' => [
                    'lc_keyword' => [
                        'type' => 'custom',
                        'tokenizer' => 'keyword',
                        'filter' => ['lowercase', 'asciifolding'],
                    ],
                ],
            ],
        ];
    }

    public function documentFor(NewsCase $case): array
    {
        $doc = [
            'uuid' => $case->uuid,
            'db_id' => $case->id,
            'title' => $case->title,
            'title_pt' => $case->title_pt,
            'summary' => $case->summary,
            'summary_pt' => $case->summary_pt,
            'criticity' => $case->criticity,
            'latest_source_host' => $case->latest_source_host,
            'latest_source_url' => $case->latest_source_url,
            'source_hosts' => $case->articles()->distinct()->pluck('source_host')->filter()->values()->all(),
            'first_at' => optional($case->first_at)->toIso8601String(),
            'latest_at' => optional($case->latest_at)->toIso8601String(),
            'source_count' => (int) $case->source_count,
            'update_count' => (int) $case->update_count,
        ];

        foreach (array_keys(config('secmon.indicator_fields')) as $field) {
            $doc[$field] = $case->indicatorValues($field);
        }

        $exploits = $case->exploits()->orderBy('exploits.id')->pluck('exploits.id')->all();
        $doc['exploits'] = array_map(fn ($id) => 'EDB-'.$id, $exploits);
        // O exploit que a IA pôs em dúvida continua achável pelo EDB, mas não acende a marca.
        $doc['has_exploit'] = $exploits !== [] && $case->exploits()->credible()->exists();

        return $doc;
    }

    /**
     * Índice criado antes de um campo novo: acrescenta ao mapping só o que
     * falta (o Elasticsearch aceita somar campos, nunca mudar um que existe).
     *
     * @return list<string> campos acrescentados
     */
    public function addMissingFields(string $index, array $properties): array
    {
        $mapping = $this->client()->indices()->getMapping(['index' => $index])->asArray();
        $current = Arr::get(Arr::first($mapping) ?? [], 'mappings.properties', []);
        $missing = array_diff_key($properties, $current);

        if ($missing !== []) {
            $this->client()->indices()->putMapping(['index' => $index, 'body' => ['properties' => $missing]]);
        }

        return array_keys($missing);
    }

    /**
     * Matéria escondida do feed público (`is_published` falso) sai da busca em
     * vez de entrar: quem grava (a análise, a fusão, os exploits) não precisa
     * saber, e uma fonte nova não a devolve ao feed.
     */
    public function put(NewsCase $case): void
    {
        if ($case->isHidden()) {
            $this->forget($case->uuid);
            $case->forceFill(['indexed_at' => null])->saveQuietly();

            return;
        }

        $this->client()->index([
            'index' => $this->index(),
            'id' => $case->uuid,
            'body' => $this->documentFor($case),
        ]);

        $case->forceFill(['indexed_at' => now()])->saveQuietly();
    }

    /**
     * Indexa as publicadas e tira da busca as escondidas, numa requisição só.
     *
     * @param  iterable<NewsCase>  $cases
     * @return int quantas foram indexadas
     */
    public function bulk(iterable $cases): int
    {
        $body = [];
        $uuids = [];
        $hidden = [];

        foreach ($cases as $case) {
            if ($case->isHidden()) {
                $body[] = ['delete' => ['_index' => $this->index(), '_id' => $case->uuid]];
                $hidden[] = $case->uuid;

                continue;
            }

            $body[] = ['index' => ['_index' => $this->index(), '_id' => $case->uuid]];
            $body[] = $this->documentFor($case);
            $uuids[] = $case->uuid;
        }

        if ($body === []) {
            return 0;
        }

        $this->client()->bulk(['body' => $body]);

        NewsCase::query()->whereIn('uuid', $uuids)->update(['indexed_at' => now()]);

        if ($hidden !== []) {
            NewsCase::query()->whereIn('uuid', $hidden)->update(['indexed_at' => null]);
        }

        return count($uuids);
    }

    /**
     * Tira da busca as matérias escondidas que ainda estão nela (`indexed_at`
     * preenchido): as que uma migration escondeu direto no banco, ou cuja
     * remoção falhou com o Elasticsearch fora. Roda no boot (secmon:es-setup) e
     * no reindex; devolve quantas saíram.
     */
    public function pruneHidden(): int
    {
        $total = 0;

        NewsCase::query()
            ->where('is_published', false)
            ->whereNotNull('indexed_at')
            ->select(['id', 'uuid', 'is_published'])
            ->chunkById(500, function ($cases) use (&$total) {
                $this->bulk($cases);
                $total += $cases->count();
            });

        return $total;
    }

    public function forget(string $uuid): void
    {
        try {
            $this->client()->delete(['index' => $this->index(), 'id' => $uuid]);
        } catch (ClientResponseException) {
            // já não existe
        }
    }

    public function refresh(): void
    {
        try {
            $this->client()->indices()->refresh(['index' => $this->index()]);
        } catch (\Throwable) {
            //
        }
    }

    public function count(): int
    {
        try {
            return (int) $this->client()->count(['index' => $this->index()])->asArray()['count'];
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Busca principal do feed. Devolve o mesmo envelope da API original.
     */
    public function search(string $query, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $from = ($page - 1) * $perPage;

        $body = [
            'from' => $from,
            'size' => $perPage,
            'track_total_hits' => true,
            'query' => $this->buildQuery($query),
            'sort' => $this->buildSort($query),
            'aggs' => [
                'criticity' => ['terms' => ['field' => 'criticity', 'size' => 10]],
                'victim_sectors' => ['terms' => ['field' => 'victim_sectors.raw', 'size' => 30]],
                'threat_actors' => ['terms' => ['field' => 'threat_actors.raw', 'size' => 30]],
            ],
        ];

        try {
            $res = $this->client()->search(['index' => $this->index(), 'body' => $body])->asArray();
        } catch (\Throwable $e) {
            return self::emptyResult($query, $page, $perPage, $this->humanError($e));
        }

        $total = (int) Arr::get($res, 'hits.total.value', 0);
        $hits = Arr::get($res, 'hits.hits', []);

        return [
            'cases' => array_map(fn ($hit) => $this->hitToCase($hit['_source']), $hits),
            'facets' => $this->facetsFrom(Arr::get($res, 'aggregations', [])),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
            'has_next' => $from + $perPage < $total,
            'has_prev' => $page > 1,
            'start_index' => $total ? $from + 1 : 0,
            'end_index' => min($from + $perPage, $total),
            'query' => $query,
            'error' => null,
        ];
    }

    /** O envelope da busca sem nenhum caso, com o motivo em `error`. */
    public static function emptyResult(string $query, int $page, int $perPage, string $error): array
    {
        return [
            'cases' => [], 'facets' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage,
            'total_pages' => 0, 'has_next' => false, 'has_prev' => false,
            'start_index' => 0, 'end_index' => 0, 'query' => $query,
            'error' => $error,
        ];
    }

    /** Agregações do dashboard, com a mesma forma da API original. */
    public function dashboard(int $windowDays = 90): array
    {
        // Os dias do gráfico são os de Brasília: notícia das 22h fica no dia dela.
        $tz = (string) config('app.display_timezone');
        $since = Carbon::now($tz)->subDays($windowDays)->startOfDay();

        $body = [
            'size' => 0,
            'track_total_hits' => true,
            'query' => ['match_all' => (object) []],
            'aggs' => [
                'criticity' => ['terms' => ['field' => 'criticity', 'size' => 10]],
                'countries' => ['terms' => ['field' => 'countries_code.raw', 'size' => 200]],
                'top_threat_actors' => ['terms' => ['field' => 'threat_actors.raw', 'size' => 10]],
                'top_cves' => ['terms' => ['field' => 'cves.raw', 'size' => 10]],
                'top_malware' => ['terms' => ['field' => 'malware_families.raw', 'size' => 10]],
                'top_victim_sectors' => ['terms' => ['field' => 'victim_sectors.raw', 'size' => 10]],
                'window' => [
                    'filter' => ['range' => ['first_at' => ['gte' => $since->toIso8601String()]]],
                    'aggs' => [
                        'activity' => [
                            'date_histogram' => [
                                'field' => 'first_at',
                                'calendar_interval' => 'day',
                                'time_zone' => $tz,
                                'min_doc_count' => 0,
                                'format' => 'yyyy-MM-dd',
                                'extended_bounds' => [
                                    'min' => $since->format('Y-m-d'),
                                    'max' => Carbon::now($tz)->format('Y-m-d'),
                                ],
                            ],
                        ],
                    ],
                ],
                'updated_7d' => [
                    'filter' => ['range' => ['latest_at' => ['gte' => Carbon::now()->subDays(7)->toIso8601String()]]],
                ],
                'critical' => ['filter' => ['term' => ['criticity' => 'critical']]],
            ],
        ];

        try {
            $res = $this->client()->search(['index' => $this->index(), 'body' => $body])->asArray();
        } catch (\Throwable $e) {
            return ['error' => $this->humanError($e), 'total_cases' => 0, 'activity' => []];
        }

        $buckets = fn (string $path) => array_map(
            fn ($b) => ['key' => $b['key_as_string'] ?? $b['key'], 'count' => $b['doc_count']],
            Arr::get($res, $path, [])
        );

        return [
            'total_cases' => (int) Arr::get($res, 'hits.total.value', 0),
            'critical_count' => (int) Arr::get($res, 'aggregations.critical.doc_count', 0),
            'updated_last_7d' => (int) Arr::get($res, 'aggregations.updated_7d.doc_count', 0),
            'window_days' => $windowDays,
            'criticity' => $buckets('aggregations.criticity.buckets'),
            'countries' => $buckets('aggregations.countries.buckets'),
            'top_threat_actors' => $buckets('aggregations.top_threat_actors.buckets'),
            'top_cves' => $buckets('aggregations.top_cves.buckets'),
            'top_malware' => $buckets('aggregations.top_malware.buckets'),
            'top_victim_sectors' => $buckets('aggregations.top_victim_sectors.buckets'),
            'activity' => $buckets('aggregations.window.activity.buckets'),
            'error' => null,
        ];
    }

    private function buildQuery(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return ['match_all' => (object) []];
        }

        return [
            'query_string' => [
                'query' => $query,
                'default_field' => 'all',
                'default_operator' => 'AND',
                'analyze_wildcard' => true,
                'allow_leading_wildcard' => false,
                'lenient' => true,
            ],
        ];
    }

    /**
     * Sem termo de busca o feed é cronológico. Com termo, relevância primeiro e
     * data como desempate.
     */
    private function buildSort(string $query): array
    {
        if (trim($query) === '') {
            return [['latest_at' => ['order' => 'desc', 'missing' => '_last']]];
        }

        return ['_score', ['latest_at' => ['order' => 'desc', 'missing' => '_last']]];
    }

    private function facetsFrom(array $aggs): array
    {
        $labels = [
            'criticity' => 'Criticity',
            'victim_sectors' => 'Victim Sectors',
            'threat_actors' => 'Threat Actors',
        ];

        $facets = [];

        foreach ($labels as $field => $label) {
            $buckets = Arr::get($aggs, "$field.buckets", []);

            if ($buckets === []) {
                continue;
            }

            $facets[] = [
                'field' => $field,
                'label' => $label,
                'buckets' => array_map(fn ($b) => ['key' => $b['key'], 'count' => $b['doc_count']], $buckets),
            ];
        }

        return $facets;
    }

    private function hitToCase(array $src): array
    {
        return [
            'uuid' => $src['uuid'] ?? null,
            'title' => $src['title'] ?? null,
            'title_pt' => $src['title_pt'] ?? null,
            'summary' => $src['summary'] ?? null,
            'summary_pt' => $src['summary_pt'] ?? null,
            'criticity' => $src['criticity'] ?? null,
            'first_at' => $src['first_at'] ?? null,
            'latest_at' => $src['latest_at'] ?? null,
            'latest_source_host' => $src['latest_source_host'] ?? null,
            'latest_source_url' => $src['latest_source_url'] ?? null,
            'source_count' => (int) ($src['source_count'] ?? 0),
            'update_count' => (int) ($src['update_count'] ?? 0),
            'victim_sectors' => $src['victim_sectors'] ?? [],
            'threat_actors' => $src['threat_actors'] ?? [],
            'cves' => $src['cves'] ?? [],
            'has_exploit' => (bool) ($src['has_exploit'] ?? false),
        ];
    }

    private function humanError(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'index_not_found')) {
            // A instrução é de quem opera o servidor: vai para o log, não para a tela do visitante.
            Log::warning('Índice de busca não existe: rode php artisan secmon:es-setup', ['index' => $this->index()]);

            return 'A busca está sendo preparada. Tente de novo em instantes.';
        }

        if (str_contains(strtolower($message), 'parse') || str_contains($message, 'query_shard')) {
            return 'Consulta inválida. Revise a sintaxe (AND, OR, NOT, aspas e curingas).';
        }

        return 'Serviço de busca indisponível no momento.';
    }
}
