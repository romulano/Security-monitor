<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Search\CaseIndex;
use App\Support\ReportLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Search\FakeElasticsearch;
use Tests\TestCase;

/**
 * A divulgação em inglês (2026-11-01): a descrição e o cartão de link (Open
 * Graph e Twitter) que o Product Hunt, o Slack e as redes leem; a interface
 * continua em português.
 */
class ShareMetaTagsTest extends TestCase
{
    use RefreshDatabase;

    private function meta(TestResponse $response, string $attribute, string $name): ?string
    {
        preg_match('/<meta '.$attribute.'="'.preg_quote($name, '/').'" content="([^"]*)"/', $response->getContent(), $m);

        return isset($m[1]) ? html_entity_decode($m[1], ENT_QUOTES) : null;
    }

    public function test_the_home_shares_an_english_card_and_keeps_the_portuguese_interface(): void
    {
        $es = FakeElasticsearch::answering([FakeElasticsearch::json(['hits' => ['total' => ['value' => 0], 'hits' => []], 'aggregations' => []])]);
        $this->app->instance(CaseIndex::class, $es->caseIndex());

        $response = $this->get(route('feed.index', ['utm_source' => 'producthunt']))->assertOk();

        $this->assertSame('Security Monitor · Threat intel news merged into cases by AI', $this->meta($response, 'property', 'og:title'));
        $this->assertStringStartsWith('Security news from research labs, government alerts', $this->meta($response, 'name', 'description'));
        $this->assertSame($this->meta($response, 'name', 'description'), $this->meta($response, 'property', 'og:description'));
        $this->assertSame(url('/img/mascote-og.png'), $this->meta($response, 'property', 'og:image'));
        // O endereço do cartão é o da página, sem a campanha.
        $this->assertSame(url('/'), $this->meta($response, 'property', 'og:url'));
        $this->assertSame('website', $this->meta($response, 'property', 'og:type'));
        $this->assertSame('summary', $this->meta($response, 'name', 'twitter:card'));

        $response->assertSee('<html lang="pt-BR"', false)->assertSee('Painel');
    }

    public function test_a_case_shares_its_english_title_and_summary_even_when_read_in_portuguese(): void
    {
        $case = NewsCase::query()->create([
            'title' => 'Acme RCE exploited in the wild',
            'title_pt' => 'RCE da Acme explorado ativamente',
            'summary' => "Attackers exploit an **Acme** RCE.\n\n### Technical Details\n- Unauthenticated.",
            'summary_pt' => "Atacantes exploram um RCE da **Acme**.\n\n### Detalhes Técnicos\n- Sem autenticação.",
            'criticity' => 'critical',
            'first_at' => now(),
            'latest_at' => now(),
        ]);
        Article::query()->create([
            'case_id' => $case->id, 'url' => 'https://example.com/acme', 'url_hash' => hash('sha256', 'acme'),
            'source_host' => 'example.com', 'title' => 'Acme RCE', 'status' => 'enriched', 'collected_at' => now(),
        ]);

        $response = $this->withCookie(ReportLanguage::COOKIE, 'pt')->get(route('feed.show', $case->uuid))
            ->assertOk()
            ->assertSee('<title>RCE da Acme explorado ativamente · Security Monitor</title>', false);

        $this->assertSame('Acme RCE exploited in the wild', $this->meta($response, 'property', 'og:title'));
        $this->assertSame('Attackers exploit an Acme RCE.', $this->meta($response, 'name', 'description'));
        $this->assertSame('Attackers exploit an Acme RCE.', $this->meta($response, 'property', 'og:description'));
        $this->assertSame('article', $this->meta($response, 'property', 'og:type'));
        $this->assertSame(route('feed.show', $case->uuid), $this->meta($response, 'property', 'og:url'));
    }

    public function test_the_dashboard_and_the_about_page_describe_themselves_in_english(): void
    {
        $this->mock(CaseIndex::class)->shouldIgnoreMissing();

        $dashboard = $this->get(route('feed.dashboard'))->assertOk();
        $this->assertSame('Security Monitor · Threat dashboard', $this->meta($dashboard, 'property', 'og:title'));
        $this->assertStringStartsWith('What the security press is reporting right now', $this->meta($dashboard, 'name', 'description'));

        $about = $this->get(route('about'))->assertOk();
        $this->assertStringStartsWith('Security Monitor is an intelligence satellite', $this->meta($about, 'name', 'description'));
        $this->assertSame($this->meta($about, 'name', 'description'), $this->meta($about, 'property', 'og:description'));
    }
}
