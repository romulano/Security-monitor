<?php

namespace Tests\Feature\Alerts;

use App\Models\AlertProfile;
use App\Services\Alerts\MatchEngine;
use App\Services\Alerts\ProfileCompiler;
use App\Services\Alerts\ProfileIndex;
use App\Services\Alerts\ProfilePreview;
use App\Services\Search\CaseIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Search\FakeElasticsearch;
use Tests\TestCase;

/** O percolator dos alertas e a prévia de 30 dias, contra um Elasticsearch de mentira. */
class ProfileIndexTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    private function index(FakeElasticsearch $es): ProfileIndex
    {
        return new ProfileIndex($es->caseIndex());
    }

    private function withQuery(array $attributes = []): AlertProfile
    {
        return $this->profile(null, $attributes + ['match_query' => 'laravel OR "acme pagamentos"', 'query_version' => 3]);
    }

    public function test_an_active_profile_is_stored_as_a_percolator_query_and_stamped(): void
    {
        $profile = $this->withQuery();
        $es = FakeElasticsearch::answering([FakeElasticsearch::json([], 200), FakeElasticsearch::json(['result' => 'created'], 201)]);

        $this->assertTrue($this->index($es)->put($profile));

        $this->assertSame('HEAD', $es->method(0));
        $this->assertSame('PUT', $es->method(1));
        $this->assertSame('/secmon_profiles/_doc/'.$profile->id, $es->path(1));
        parse_str($es->requests()[1]->getUri()->getQuery(), $query);
        $this->assertSame('wait_for', $query['refresh']);

        $body = $es->body(1);
        $this->assertSame($profile->id, $body['profile_id']);
        $this->assertSame(3, $body['query_version']);
        $this->assertSame('laravel OR "acme pagamentos"', $body['query']['query_string']['query']);
        $this->assertSame('OR', $body['query']['query_string']['default_operator']);
        $this->assertTrue($body['query']['query_string']['allow_leading_wildcard']);
        $this->assertNotNull($profile->fresh()->percolated_at);
    }

    public function test_the_index_is_created_first_when_it_does_not_exist_yet(): void
    {
        $profile = $this->withQuery();
        $es = FakeElasticsearch::answering([
            FakeElasticsearch::json([], 404),
            FakeElasticsearch::json(['acknowledged' => true]),
            FakeElasticsearch::json(['result' => 'created'], 201),
        ]);

        $this->assertTrue($this->index($es)->put($profile));

        $mappings = $es->body(1)['mappings']['properties'];
        $this->assertSame('/secmon_profiles', $es->path(1));
        $this->assertSame('/secmon_profiles/_doc/'.$profile->id, $es->path(2));
        $this->assertSame('percolator', $mappings['query']['type']);
        $this->assertSame('long', $mappings['profile_id']['type']);
        $this->assertArrayHasKey('cves', $mappings, 'mesmo mapping dos casos');
    }

    public function test_a_paused_profile_or_one_without_a_query_is_removed_instead(): void
    {
        $paused = $this->withQuery(['status' => 'paused', 'percolated_at' => now()]);
        $empty = $this->withQuery(['match_query' => null, 'percolated_at' => now()]);
        $es = FakeElasticsearch::answering([FakeElasticsearch::json(['result' => 'deleted']), FakeElasticsearch::json(['result' => 'deleted'])]);
        $index = $this->index($es);

        $this->assertTrue($index->put($paused));
        $this->assertTrue($index->put($empty));

        $this->assertSame('DELETE', $es->method(0));
        $this->assertSame('/secmon_profiles/_doc/'.$paused->id, $es->path(0));
        $this->assertSame('DELETE', $es->method(1));
        $this->assertNull($paused->fresh()->percolated_at);
        $this->assertNull($empty->fresh()->percolated_at);
    }

    public function test_forgetting_a_profile_that_was_never_stored_is_not_an_error(): void
    {
        $profile = $this->withQuery(['percolated_at' => now()]);
        $es = FakeElasticsearch::answering([FakeElasticsearch::json(['result' => 'not_found'], 404)]);

        $this->index($es)->forget($profile);

        $this->assertNull($profile->fresh()->percolated_at);
    }

    public function test_put_reports_failure_when_the_service_is_down(): void
    {
        $profile = $this->withQuery();
        $es = FakeElasticsearch::answering([FakeElasticsearch::json([], 200), FakeElasticsearch::json(['error' => 'boom'], 503)]);

        $this->assertFalse($this->index($es)->put($profile));
        $this->assertNull($profile->fresh()->percolated_at);
    }

    public function test_percolating_a_case_returns_the_candidate_profile_ids(): void
    {
        $case = $this->newsCase('Laravel framework flaw', 'high', ['cves' => ['CVE-2026-1111']]);
        $es = FakeElasticsearch::answering([FakeElasticsearch::json(['hits' => ['hits' => [
            ['_source' => ['profile_id' => 7]],
            ['_source' => ['profile_id' => '9']],
        ]]])]);

        $ids = $this->index($es)->percolate($case);

        $this->assertSame([7, 9], $ids);
        $this->assertSame('/secmon_profiles/_search', $es->path());
        $percolate = $es->body()['query']['percolate'];
        $this->assertSame('query', $percolate['field']);
        $this->assertSame('Laravel framework flaw', $percolate['document']['title']);
        $this->assertSame(1000, $es->body()['size']);
    }

    public function test_percolating_returns_null_when_the_index_is_unavailable_so_every_profile_is_evaluated(): void
    {
        $es = FakeElasticsearch::answering([FakeElasticsearch::json([], 503)]);

        $this->assertNull($this->index($es)->percolate($this->newsCase('Qualquer')));
    }

    public function test_exists_is_false_when_the_service_is_down_and_drop_deletes_only_what_exists(): void
    {
        $down = FakeElasticsearch::answering([FakeElasticsearch::json([], 503)]);
        $this->assertFalse($this->index($down)->exists());

        $there = FakeElasticsearch::answering([FakeElasticsearch::json([], 200), FakeElasticsearch::json(['acknowledged' => true])]);
        $this->index($there)->drop();
        $this->assertSame('DELETE', $there->method(1));
        $this->assertSame('/secmon_profiles', $there->path(1));
    }

    public function test_preview_counts_the_alerts_the_last_days_would_have_produced(): void
    {
        $profile = $this->profile(null, [], ['laravel']);
        $matching = $this->newsCase('Laravel framework flaw', 'high', ['affected_products' => ['Laravel Framework 11.x'], 'cves' => ['CVE-2026-1111']]);
        $unrelated = $this->newsCase('Something about printers', 'high', ['affected_products' => ['Acme Printer']]);

        $docs = app(CaseIndex::class);
        $es = FakeElasticsearch::answering([FakeElasticsearch::json(['hits' => ['hits' => [
            ['_source' => $docs->documentFor($matching)],
            ['_source' => $docs->documentFor($unrelated)],
        ]]])]);

        $preview = (new ProfilePreview($es->caseIndex(), app(ProfileCompiler::class), app(MatchEngine::class)))->run($profile, 14);

        $this->assertSame(1, $preview['total']);
        $this->assertSame(['high' => 1], $preview['by_level']);
        $this->assertSame(0.5, $preview['per_week']);
        $this->assertSame($matching->uuid, $preview['examples'][0]['uuid']);
        $this->assertNull($preview['error']);

        $body = $es->body();
        $this->assertSame(500, $body['size']);
        $this->assertArrayHasKey('range', $body['query']['bool']['filter'][0]);
    }

    public function test_preview_degrades_to_a_message_when_the_search_service_is_down(): void
    {
        $profile = $this->profile(null, [], ['laravel']);
        $es = FakeElasticsearch::answering([FakeElasticsearch::json([], 503)]);

        $preview = (new ProfilePreview($es->caseIndex(), app(ProfileCompiler::class), app(MatchEngine::class)))->run($profile);

        $this->assertSame(0, $preview['total']);
        $this->assertStringContainsString('Prévia indisponível', $preview['error']);
    }

    public function test_preview_of_a_profile_with_nothing_to_look_for_does_not_even_search(): void
    {
        $profile = $this->profile(null, ['company_name' => null, 'include_brand_mentions' => false]);
        $es = FakeElasticsearch::answering([]);

        $preview = (new ProfilePreview($es->caseIndex(), app(ProfileCompiler::class), app(MatchEngine::class)))->run($profile);

        $this->assertSame(0, $preview['total']);
        $this->assertCount(0, $es->requests());
    }

    public function test_query_for_wraps_the_string_in_a_query_string_with_or_semantics(): void
    {
        $query = ProfileIndex::queryFor('a OR b');

        $this->assertSame('a OR b', $query['query_string']['query']);
        $this->assertSame('all', $query['query_string']['default_field']);
        $this->assertSame('OR', $query['query_string']['default_operator']);
    }
}
