<?php

namespace Tests\Feature;

use App\Models\CrawlRun;
use App\Models\LogEntry;
use App\Models\NewsCase;
use App\Models\Source;
use Dom\Element;
use Dom\HTMLDocument;
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

    private function logEntry(string $day, string $title, string $text = 'O que mudou.'): LogEntry
    {
        return LogEntry::create(['logged_on' => $day, 'title' => $title, 'text' => $text]);
    }

    /** @return list<Element> os itens do <ol> do diário de bordo */
    private function logItems(): array
    {
        $html = $this->get(route('about'))->assertOk()->getContent();
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        $heading = collect($doc->querySelectorAll('h3'))->first(fn ($h) => trim($h->textContent) === 'Diário de bordo');

        return collect($heading->nextElementSibling->childNodes)->whereInstanceOf(Element::class)->values()->all();
    }

    public function test_log_shows_five_items_with_older_ones_behind_see_more(): void
    {
        LogEntry::query()->delete();
        $this->logEntry('2026-10-19', 'Lançamento');
        $this->logEntry('2026-10-21', 'Radar de domínios');
        $this->logEntry('2026-10-20', 'Radar de exploits');
        $this->logEntry('2026-10-21', 'Acoplagem');
        $this->logEntry('2026-10-22', 'Sonda no GitHub');

        $items = $this->logItems();

        $this->assertCount(5, $items);
        $this->assertStringContainsString('ver mais', $items[0]->textContent);
        $older = $items[0]->querySelector('details')->textContent;
        $this->assertStringContainsString('Lançamento', $older);
        $this->assertStringContainsString('Radar de exploits', $older);
        $this->assertStringContainsString('Radar de domínios', $items[1]->textContent);
        $this->assertStringContainsString('Acoplagem', $items[2]->textContent);
        $this->assertStringContainsString('Sonda no GitHub', $items[3]->textContent);
        $this->assertStringContainsString('Próxima missão', $items[4]->textContent);
    }

    public function test_log_dates_are_shown_as_day_month_year(): void
    {
        LogEntry::query()->delete();
        $this->logEntry('2026-10-21', 'Acoplagem');

        $this->get(route('about'))->assertOk()->assertSee('21.10.2026');
    }

    public function test_few_entries_show_no_see_more(): void
    {
        LogEntry::query()->delete();
        $this->logEntry('2026-10-19', 'Lançamento');

        $items = $this->logItems();

        $this->assertCount(2, $items);
        $this->assertStringContainsString('Lançamento', $items[0]->textContent);
        $this->assertStringNotContainsString('ver mais', $items[0]->textContent);
        $this->assertStringContainsString('Próxima missão', $items[1]->textContent);
    }

    public function test_an_empty_log_shows_only_the_next_mission(): void
    {
        LogEntry::query()->delete();

        $items = $this->logItems();

        $this->assertCount(1, $items);
        $this->assertStringContainsString('Próxima missão', $items[0]->textContent);
    }

    public function test_the_log_that_launched_with_the_site_comes_from_the_migration(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('Sonda no GitHub')
            ->assertSee('19.10.2026');
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
