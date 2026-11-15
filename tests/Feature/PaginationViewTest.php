<?php

namespace Tests\Feature;

use App\Models\CrawlRun;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Tests\TestCase;

/** A paginação do site inteiro: as views próprias em vendor/pagination, escuras e em português. */
class PaginationViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_full_paginator_is_dark_and_in_portuguese(): void
    {
        $paginator = new LengthAwarePaginator(range(26, 50), 1234, 25, 2, ['path' => '/lista']);

        $html = (string) $paginator->links();

        $this->assertStringContainsString('aria-label="Paginação"', $html);
        $this->assertStringContainsString('Anterior', $html);
        $this->assertStringContainsString('Próxima', $html);
        $this->assertStringContainsString('26–50 de 1.234', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('aria-label="Página 3"', $html);
        $this->assertStringContainsString('bg-ink-900', $html);
        $this->assertStringNotContainsString('bg-white', $html);
        $this->assertStringNotContainsString('dark:', $html);
        $this->assertStringNotContainsString('Showing', $html);
        $this->assertStringNotContainsString('Previous', $html);
    }

    public function test_a_single_page_renders_nothing(): void
    {
        $paginator = new LengthAwarePaginator(range(1, 10), 10, 25, 1, ['path' => '/lista']);

        $this->assertSame('', trim((string) $paginator->links()));
    }

    public function test_the_simple_paginator_has_no_total(): void
    {
        $paginator = new Paginator(range(1, 26), 25, 2, ['path' => '/lista']);

        $html = (string) $paginator->links();

        $this->assertStringContainsString('Anterior', $html);
        $this->assertStringContainsString('Página 2', $html);
        $this->assertStringContainsString('Próxima', $html);
        $this->assertStringNotContainsString(' de ', $html);
        $this->assertStringNotContainsString('bg-white', $html);
    }

    public function test_a_real_screen_uses_the_new_view(): void
    {
        $source = Source::create(['name' => 'Feed', 'host' => 'feed.test', 'feed_url' => 'https://feed.test/rss']);
        foreach (range(1, 41) as $i) {
            CrawlRun::create(['source_id' => $source->id, 'trigger' => 'schedule', 'status' => 'ok', 'started_at' => now()->subMinutes($i)]);
        }

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.runs'))
            ->assertOk()
            ->assertSee('aria-label="Paginação"', false)
            ->assertSee('1–40 de 41')
            ->assertSee('Próxima')
            ->assertDontSee('Showing')
            ->assertDontSee('bg-white', false);
    }
}
