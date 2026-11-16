<?php

namespace Tests\Feature;

use App\Models\CrawlRun;
use App\Models\NewsCase;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AboutPageTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $host, bool $enabled = true): Source
    {
        return Source::create([
            'name' => $host,
            'host' => $host,
            'feed_url' => "https://{$host}/feed",
            'enabled' => $enabled,
        ]);
    }

    private function newsCase(string $title): NewsCase
    {
        return NewsCase::create([
            'title' => $title,
            'fingerprint' => md5($title),
            'criticity' => 'high',
            'first_at' => now(),
            'latest_at' => now(),
            'source_count' => 1,
            'update_count' => 1,
        ]);
    }

    public function test_telemetry_comes_from_the_database(): void
    {
        $source = $this->source('a.test');
        $this->source('b.test');
        $this->source('desligada.test', enabled: false);
        $this->newsCase('Caso um');
        CrawlRun::create(['source_id' => $source->id, 'status' => 'ok', 'started_at' => now()->subMinutes(12)]);

        $this->get(route('about'))
            ->assertOk()
            ->assertSeeInOrder(['Fontes monitoradas', '2<small', 'Casos analisados', '1<small'], false)
            ->assertSee('Sinal ● estável')
            ->assertDontSee('Sinal fraco');
    }

    public function test_stale_sweep_shows_a_weak_signal(): void
    {
        CrawlRun::create(['status' => 'ok', 'started_at' => now()->subHours(5)]);

        $this->get(route('about'))
            ->assertOk()
            ->assertSee('Sinal ● fraco')
            ->assertSee('Sinal fraco');
    }

    public function test_page_works_before_the_first_sweep(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('ainda não houve')
            ->assertSee('Sinal fraco');
    }

    public function test_guest_is_offered_an_invite_and_header_links_here(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertSee(route('alerts.index'))
            ->assertSee('href="'.route('about').'"', false);
    }

    public function test_copy_speaks_of_the_team_and_both_channels(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('leva para a sua')
            ->assertSee('encostava nas estrelas')
            ->assertSee('recebe no Slack ou no Discord')
            ->assertSee('chegam pelo Telegram')
            ->assertDontSee('Terra só o que importa');
    }
}
