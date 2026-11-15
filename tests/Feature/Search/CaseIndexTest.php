<?php

namespace Tests\Feature\Search;

use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Alerts\AlertsTestHelpers;
use Tests\TestCase;

/** A camada de busca do feed, contra um Elasticsearch de mentira (FakeElasticsearch). */
class CaseIndexTest extends TestCase
{
    use AlertsTestHelpers;
    use RefreshDatabase;

    private function hit(string $title, array $extra = []): array
    {
        return ['_source' => $extra + [
            'uuid' => 'uuid-'.md5($title),
            'title' => $title,
            'criticity' => 'high',
            'source_count' => '2',
            'cves' => ['CVE-2026-1'],
            'has_exploit' => 1,
        ]];
    }

    private function searchResult(array $hits, int $total, array $aggs = []): Response
    {
        return FakeElasticsearch::json(['hits' => ['total' => ['value' => $total], 'hits' => $hits], 'aggregations' => $aggs]);
    }

    public function test_an_empty_search_lists_everything_from_the_newest(): void
    {
        $es = FakeElasticsearch::answering([$this->searchResult([$this->hit('A'), $this->hit('B')], 45)]);

        $result = $es->caseIndex()->search('  ', page: 2, perPage: 20);

        $this->assertSame('POST', $es->method());
        $this->assertSame('/secmon_cases/_search', $es->path());

        $body = $es->body();
        $this->assertSame(20, $body['from']);
        $this->assertSame(20, $body['size']);
        $this->assertSame([], $body['query']['match_all']);
        $this->assertSame([['latest_at' => ['order' => 'desc', 'missing' => '_last']]], $body['sort']);

        $this->assertSame(45, $result['total']);
        $this->assertSame(3, $result['total_pages']);
        $this->assertTrue($result['has_next']);
        $this->assertTrue($result['has_prev']);
        $this->assertSame(21, $result['start_index']);
        $this->assertSame(40, $result['end_index']);
        $this->assertNull($result['error']);
        $this->assertSame(['A', 'B'], array_column($result['cases'], 'title'));
    }

    public function test_a_search_term_becomes_a_query_string_ranked_by_score_then_date(): void
    {
        $es = FakeElasticsearch::answering([$this->searchResult([], 0)]);

        $result = $es->caseIndex()->search('threat_actors:"Scattered Spider" AND cves:CVE-2026-*');

        $body = $es->body();
        $queryString = $body['query']['query_string'];
        $this->assertSame('threat_actors:"Scattered Spider" AND cves:CVE-2026-*', $queryString['query']);
        $this->assertSame('AND', $queryString['default_operator']);
        $this->assertFalse($queryString['allow_leading_wildcard']);
        $this->assertTrue($queryString['lenient']);
        $this->assertSame('_score', $body['sort'][0]);

        $this->assertSame(0, $result['total']);
        $this->assertFalse($result['has_next']);
        $this->assertFalse($result['has_prev']);
        $this->assertSame(0, $result['start_index']);
    }

    public function test_a_hit_is_trimmed_to_the_fields_the_feed_uses(): void
    {
        $es = FakeElasticsearch::answering([$this->searchResult([$this->hit('Acme', ['secret_field' => 'x'])], 1)]);

        $case = $es->caseIndex()->search('acme')['cases'][0];

        $this->assertSame('Acme', $case['title']);
        $this->assertSame(2, $case['source_count']);
        $this->assertTrue($case['has_exploit']);
        $this->assertSame(['CVE-2026-1'], $case['cves']);
        $this->assertSame([], $case['victim_sectors']);
        $this->assertArrayNotHasKey('secret_field', $case);
    }

    public function test_facets_keep_only_the_aggregations_that_came_back_with_buckets(): void
    {
        $es = FakeElasticsearch::answering([$this->searchResult([], 0, [
            'criticity' => ['buckets' => [['key' => 'high', 'doc_count' => 4], ['key' => 'low', 'doc_count' => 1]]],
            'victim_sectors' => ['buckets' => []],
            'threat_actors' => ['buckets' => [['key' => 'APT29', 'doc_count' => 2]]],
        ])]);

        $facets = $es->caseIndex()->search('x')['facets'];

        $this->assertSame(['criticity', 'threat_actors'], array_column($facets, 'field'));
        $this->assertSame([['key' => 'high', 'count' => 4], ['key' => 'low', 'count' => 1]], $facets[0]['buckets']);
        $this->assertSame('Threat Actors', $facets[1]['label']);
    }

    public function test_a_failing_search_returns_an_empty_page_with_a_message_the_person_can_act_on(): void
    {
        $missing = FakeElasticsearch::answering([FakeElasticsearch::json(['error' => ['type' => 'index_not_found_exception']], 404)]);
        $invalid = FakeElasticsearch::answering([FakeElasticsearch::json(['error' => ['type' => 'query_shard_exception']], 400)]);
        $down = FakeElasticsearch::answering([FakeElasticsearch::json(['error' => 'boom'], 503)]);

        $a = $missing->caseIndex()->search('x');
        $b = $invalid->caseIndex()->search('((');
        $c = $down->caseIndex()->search('x');

        // Nada de comando para o visitante; a instrução do operador vai para o log.
        $this->assertSame('A busca está sendo preparada. Tente de novo em instantes.', $a['error']);
        $this->assertStringNotContainsString('artisan', $a['error']);
        $this->assertStringContainsString('Consulta inválida', $b['error']);
        $this->assertStringContainsString('indisponível', $c['error']);

        foreach ([$a, $b, $c] as $page) {
            $this->assertSame([], $page['cases']);
            $this->assertSame(0, $page['total']);
        }
    }

    public function test_the_dashboard_turns_the_aggregations_into_counted_keys(): void
    {
        $es = FakeElasticsearch::answering([FakeElasticsearch::json([
            'hits' => ['total' => ['value' => 120]],
            'aggregations' => [
                'critical' => ['doc_count' => 9],
                'updated_7d' => ['doc_count' => 14],
                'criticity' => ['buckets' => [['key' => 'critical', 'doc_count' => 9]]],
                'top_cves' => ['buckets' => [['key' => 'CVE-2026-1', 'doc_count' => 3]]],
                'window' => ['activity' => ['buckets' => [
                    ['key' => 1, 'key_as_string' => '2026-10-22', 'doc_count' => 5],
                    ['key' => 2, 'key_as_string' => '2026-10-23', 'doc_count' => 0],
                ]]],
            ],
        ])]);

        $dashboard = $es->caseIndex()->dashboard(30);

        $this->assertSame(120, $dashboard['total_cases']);
        $this->assertSame(9, $dashboard['critical_count']);
        $this->assertSame(14, $dashboard['updated_last_7d']);
        $this->assertSame(30, $dashboard['window_days']);
        $this->assertSame([['key' => 'CVE-2026-1', 'count' => 3]], $dashboard['top_cves']);
        $this->assertSame([['key' => '2026-10-22', 'count' => 5], ['key' => '2026-10-23', 'count' => 0]], $dashboard['activity']);
        $this->assertSame([], $dashboard['top_malware']);
        $this->assertNull($dashboard['error']);

        $this->assertSame(0, $es->body()['size']);
        $this->assertSame('day', $es->body()['aggs']['window']['aggs']['activity']['date_histogram']['calendar_interval']);
    }

    public function test_the_dashboard_survives_the_search_service_being_down(): void
    {
        $es = FakeElasticsearch::answering([FakeElasticsearch::json([], 503)]);

        $dashboard = $es->caseIndex()->dashboard();

        $this->assertSame(0, $dashboard['total_cases']);
        $this->assertSame([], $dashboard['activity']);
        $this->assertNotNull($dashboard['error']);
    }

    public function test_the_document_has_the_indicators_the_sources_and_the_exploit_flag(): void
    {
        $case = $this->newsCase('Acme RCE', 'critical', ['cves' => ['CVE-2026-1', 'CVE-2026-2'], 'threat_actors' => ['APT29']]);
        $es = FakeElasticsearch::answering([]);

        $doc = $es->caseIndex()->documentFor($case);

        $this->assertSame($case->uuid, $doc['uuid']);
        $this->assertSame('Acme RCE', $doc['title']);
        $this->assertSame('critical', $doc['criticity']);
        $this->assertSame(['CVE-2026-1', 'CVE-2026-2'], $doc['cves']);
        $this->assertSame(['APT29'], $doc['threat_actors']);
        $this->assertSame(['example.com'], $doc['source_hosts']);
        $this->assertSame([], $doc['exploits']);
        $this->assertFalse($doc['has_exploit']);
    }

    public function test_put_indexes_the_case_under_its_uuid_and_stamps_indexed_at(): void
    {
        $case = $this->newsCase('Acme RCE');
        $es = FakeElasticsearch::answering([FakeElasticsearch::json(['result' => 'created'], 201)]);

        $es->caseIndex()->put($case);

        $this->assertSame('PUT', $es->method());
        $this->assertSame('/secmon_cases/_doc/'.$case->uuid, $es->path());
        $this->assertSame('Acme RCE', $es->body()['title']);
        $this->assertNotNull($case->fresh()->indexed_at);
    }

    public function test_bulk_sends_one_action_line_and_one_document_per_case_and_counts_them(): void
    {
        $a = $this->newsCase('Caso A');
        $b = $this->newsCase('Caso B');
        $es = FakeElasticsearch::answering([FakeElasticsearch::json(['errors' => false, 'items' => []])]);

        $count = $es->caseIndex()->bulk([$a, $b]);

        $this->assertSame(2, $count);
        $this->assertSame('/_bulk', $es->path());

        $lines = array_values(array_filter(explode("\n", (string) $es->requests()[0]->getBody())));
        $this->assertCount(4, $lines);
        $this->assertSame(['index' => ['_index' => 'secmon_cases', '_id' => $a->uuid]], json_decode($lines[0], true));
        $this->assertSame('Caso B', json_decode($lines[3], true)['title']);
        $this->assertNotNull($a->fresh()->indexed_at);
        $this->assertNotNull($b->fresh()->indexed_at);
    }

    public function test_bulk_of_nothing_makes_no_request(): void
    {
        $es = FakeElasticsearch::answering([]);

        $this->assertSame(0, $es->caseIndex()->bulk([]));
        $this->assertCount(0, $es->requests());
    }

    public function test_forget_ignores_a_case_that_is_already_gone(): void
    {
        $es = FakeElasticsearch::answering([FakeElasticsearch::json(['result' => 'not_found'], 404)]);

        $es->caseIndex()->forget('uuid-que-nao-existe');

        $this->assertSame('DELETE', $es->method());
        $this->assertSame('/secmon_cases/_doc/uuid-que-nao-existe', $es->path());
    }

    public function test_count_and_ping_answer_zero_and_false_when_the_service_is_down(): void
    {
        $up = FakeElasticsearch::answering([FakeElasticsearch::json(['count' => 7])]);
        $down = FakeElasticsearch::answering([FakeElasticsearch::json([], 503), FakeElasticsearch::json([], 503)]);

        $this->assertSame(7, $up->caseIndex()->count());
        $this->assertSame(0, $down->caseIndex()->count());
        $this->assertFalse($down->caseIndex()->ping());
    }

    public function test_create_does_nothing_when_the_index_exists_and_sends_settings_and_mapping_when_not(): void
    {
        $exists = FakeElasticsearch::answering([FakeElasticsearch::json([], 200)]);
        $exists->caseIndex()->create();
        $this->assertCount(1, $exists->requests(), 'só a consulta de existência');
        $this->assertSame('HEAD', $exists->method());

        $missing = FakeElasticsearch::answering([FakeElasticsearch::json([], 404), FakeElasticsearch::json(['acknowledged' => true])]);
        $missing->caseIndex()->create();

        $this->assertSame('PUT', $missing->method(1));
        $this->assertSame('/secmon_cases', $missing->path(1));
        $body = $missing->body(1);
        $this->assertArrayHasKey('analysis', $body['settings']);
        $this->assertSame('keyword', $body['mappings']['properties']['uuid']['type']);
        $this->assertArrayHasKey('cves', $body['mappings']['properties']);
    }

    public function test_drop_only_deletes_an_index_that_exists(): void
    {
        $gone = FakeElasticsearch::answering([FakeElasticsearch::json([], 404)]);
        $gone->caseIndex()->drop();
        $this->assertCount(1, $gone->requests());

        $there = FakeElasticsearch::answering([FakeElasticsearch::json([], 200), FakeElasticsearch::json(['acknowledged' => true])]);
        $there->caseIndex()->drop();
        $this->assertSame('DELETE', $there->method(1));
        $this->assertSame('/secmon_cases', $there->path(1));
    }

    public function test_add_missing_fields_sends_only_what_the_index_does_not_have_yet(): void
    {
        $es = FakeElasticsearch::answering([
            FakeElasticsearch::json(['secmon_cases' => ['mappings' => ['properties' => ['uuid' => ['type' => 'keyword']]]]]),
            FakeElasticsearch::json(['acknowledged' => true]),
        ]);

        $added = $es->caseIndex()->addMissingFields('secmon_cases', [
            'uuid' => ['type' => 'keyword'],
            'has_exploit' => ['type' => 'boolean'],
        ]);

        $this->assertSame(['has_exploit'], $added);
        $this->assertSame('PUT', $es->method(1));
        $this->assertSame('/secmon_cases/_mapping', $es->path(1));
        $this->assertSame(['properties' => ['has_exploit' => ['type' => 'boolean']]], $es->body(1));
    }

    public function test_add_missing_fields_does_not_touch_the_index_when_nothing_is_missing(): void
    {
        $es = FakeElasticsearch::answering([
            FakeElasticsearch::json(['secmon_cases' => ['mappings' => ['properties' => ['uuid' => ['type' => 'keyword']]]]]),
        ]);

        $this->assertSame([], $es->caseIndex()->addMissingFields('secmon_cases', ['uuid' => ['type' => 'keyword']]));
        $this->assertCount(1, $es->requests());
    }

    public function test_the_index_name_comes_from_the_config(): void
    {
        config(['secmon.elasticsearch.index' => 'outro_indice']);
        $es = FakeElasticsearch::answering([FakeElasticsearch::json(['count' => 1])]);

        $es->caseIndex()->count();

        $this->assertSame('/outro_indice/_count', $es->path());
    }
}
