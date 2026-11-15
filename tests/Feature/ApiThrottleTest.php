<?php

namespace Tests\Feature;

use App\Services\Search\CaseIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** O que consulta o Elasticsearch para visitante anônimo tem limite por IP. */
class ApiThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $page = ['cases' => [], 'facets' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'total_pages' => 0,
            'has_next' => false, 'has_prev' => false, 'start_index' => 0, 'end_index' => 0, 'query' => '', 'error' => null];
        $this->mock(CaseIndex::class)->shouldReceive('search')->andReturn($page);
    }

    public function test_the_public_api_answers_429_after_sixty_requests_a_minute_from_one_ip(): void
    {
        foreach (range(1, 60) as $_) {
            $this->getJson('/api/news')->assertOk();
        }

        $this->getJson('/api/news')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_the_limit_is_per_ip_and_covers_every_api_endpoint(): void
    {
        foreach (range(1, 60) as $_) {
            $this->getJson('/api/news');
        }

        $this->getJson('/api/news/updates')->assertStatus(429);
        $this->getJson('/api/news/dashboard')->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/api/news')->assertOk();
    }

    public function test_the_api_reports_the_remaining_requests(): void
    {
        $this->getJson('/api/news')->assertOk()->assertHeader('X-RateLimit-Limit', 60)->assertHeader('X-RateLimit-Remaining', 59);
    }

    public function test_the_feed_search_is_limited_but_the_plain_page_is_not(): void
    {
        foreach (range(1, 70) as $_) {
            $this->get('/')->assertOk();
        }

        foreach (range(1, 60) as $n) {
            $this->get('/?q=termo'.$n)->assertOk();
        }

        $this->get('/?q=mais-um')->assertStatus(429);
        $this->get('/')->assertOk();
    }
}
