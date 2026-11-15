<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Services\Enrichment\HermesEnricher;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class HermesEnricherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['secmon.enricher.hermes.token' => 'segredo']);
        Settings::flush();
        Settings::setMany([
            'enricher.hermes_url' => 'http://hermes.test:8765',
            'enricher.hermes_model' => 'sonnet',
            'enricher.hermes_effort' => 'low',
        ]);
    }

    private function article(): Article
    {
        return new Article([
            'url' => 'https://example.com/a',
            'source_host' => 'example.com',
            'original_title' => 'Acme patches CVE-2026-1234',
            'content' => 'Acme fixed an actively exploited RCE tracked as CVE-2026-1234.',
        ]);
    }

    public function test_enrich_sends_openai_style_request_and_parses_fenced_json(): void
    {
        Http::fake([
            'hermes.test:8765/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => "```json\n".json_encode([
                    'title' => 'Acme RCE exploited in the wild',
                    'summary' => 'Acme fixed **CVE-2026-1234**.',
                    'criticity' => 'critical',
                    'cves' => ['CVE-2026-1234'],
                ])."\n```"]]],
            ]),
        ]);

        $result = (new HermesEnricher)->enrich($this->article());

        $this->assertSame('hermes', $result->driver);
        $this->assertSame('Acme RCE exploited in the wild', $result->title);
        $this->assertSame('critical', $result->criticity);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'http://hermes.test:8765/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer segredo')
                && $request['model'] === 'sonnet'
                && $request['reasoning_effort'] === 'medium'
                && ! isset($request['response_format'])
                && str_contains($request['messages'][0]['content'], 'JSON Schema');
        });
    }

    public function test_the_analysis_researches_the_web_and_can_be_turned_off(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"title":"x","summary":"y"}']]]])]);

        (new HermesEnricher)->enrich($this->article());

        Http::assertSent(fn (Request $r) => $r['tools'] === 'WebSearch,WebFetch'
            && $r['max_turns'] === 12
            && $r['reasoning_effort'] === 'medium'
            && str_contains($r['messages'][0]['content'], 'official channels')
            && str_contains($r['messages'][0]['content'], '### Fix Details'));

        config(['secmon.enricher.hermes.research' => false]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"title":"x","summary":"y"}']]]])]);

        (new HermesEnricher)->enrich($this->article());

        Http::assertSent(fn (Request $r) => ! isset($r['tools']) && ! isset($r['max_turns']) && $r['reasoning_effort'] === 'low');
    }

    public function test_the_research_keeps_an_effort_above_its_floor(): void
    {
        Settings::setMany(['enricher.hermes_effort' => 'high']);

        $this->assertSame('high', HermesEnricher::extra()['reasoning_effort']);

        Settings::setMany(['enricher.hermes_effort' => '']);

        $this->assertArrayNotHasKey('reasoning_effort', array_filter(HermesEnricher::extra(), fn ($v) => $v !== ''));
    }

    public function test_empty_model_and_effort_fall_back_to_hermes_defaults(): void
    {
        Settings::setMany(['enricher.hermes_model' => '', 'enricher.hermes_effort' => '']);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"title":"x","summary":"y"}']]]])]);

        (new HermesEnricher)->enrich($this->article());

        Http::assertSent(fn (Request $r) => ! isset($r['model']) && ! isset($r['reasoning_effort']));
    }

    public function test_enrich_surfaces_bridge_errors(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'claude saiu com código 1']], 502)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hermes HTTP 502: claude saiu com código 1');

        (new HermesEnricher)->enrich($this->article());
    }

    public function test_status_checks_health_and_token_without_spending_a_call(): void
    {
        Http::fake([
            'hermes.test:8765/healthz' => Http::response(['ok' => true, 'servico' => 'hermes', 'modelo' => 'sonnet', 'chamadas' => 3]),
            'hermes.test:8765/v1/chat/completions' => Http::response(['error' => ['message' => 'faltou `messages`']], 400),
        ]);

        $status = (new HermesEnricher)->status();

        $this->assertTrue($status['online']);
        $this->assertSame('aceito', $status['token']);
        $this->assertSame(3, $status['chamadas']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['messages'] === []);
    }

    public function test_status_reports_wrong_token(): void
    {
        Http::fake([
            'hermes.test:8765/healthz' => Http::response(['ok' => true, 'servico' => 'hermes']),
            'hermes.test:8765/v1/chat/completions' => Http::response([], 401),
        ]);

        $this->assertSame('recusado', (new HermesEnricher)->status()['token']);
    }

    public function test_status_reports_offline_bridge(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $status = (new HermesEnricher)->status();

        $this->assertFalse($status['online']);
        $this->assertStringContainsString('Connection refused', $status['error']);
    }
}
