<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Services\Enrichment\EnricherManager;
use App\Services\Enrichment\OpenRouterEnricher;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** O driver OpenRouter do enriquecimento: o protocolo da OpenAI, com a chave dele, a identificação e só provedores com saída estruturada. */
class OpenRouterEnricherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://example.com',
            'secmon.enricher.openrouter.api_key' => 'sk-or-teste',
            'secmon.enricher.openrouter.base_url' => 'https://openrouter.test/api/v1/',
            'secmon.enricher.openrouter.model' => 'openai/gpt-4o-mini',
            'secmon.enricher.openrouter.max_tokens' => 4321,
        ]);
        Settings::flush();
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

    public function test_enrich_goes_to_openrouter_with_the_chosen_model_and_structured_output_only(): void
    {
        Settings::set('enricher.openrouter_model', 'anthropic/claude-sonnet-4.5');
        Http::fake(['openrouter.test/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'title' => 'Acme RCE explorada', 'summary' => 'A Acme corrigiu a **CVE-2026-1234**.', 'criticity' => 'critical', 'cves' => ['CVE-2026-1234'],
        ])], 'finish_reason' => 'stop']]])]);

        $result = (new OpenRouterEnricher)->enrich($this->article());

        $this->assertSame(['openrouter', 'Acme RCE explorada', 'critical'], [$result->driver, $result->title, $result->criticity]);
        Http::assertSent(function (Request $request) {
            $body = $request->data();
            $this->assertSame('https://openrouter.test/api/v1/chat/completions', $request->url());
            $this->assertSame(['Bearer sk-or-teste'], $request->header('Authorization'));
            $this->assertSame([['https://example.com'], ['Security Monitor']], [$request->header('HTTP-Referer'), $request->header('X-Title')]);
            $this->assertSame(['anthropic/claude-sonnet-4.5', 4321, ['require_parameters' => true]], [$body['model'], $body['max_tokens'], $body['provider']]);
            $this->assertSame('json_schema', $body['response_format']['type']);

            return true;
        });
    }

    public function test_without_the_key_the_driver_is_not_ready_and_the_errors_say_openrouter(): void
    {
        $this->assertTrue((new OpenRouterEnricher)->isReady());
        $this->assertInstanceOf(OpenRouterEnricher::class, app(EnricherManager::class)->driver('openrouter'));
        $this->assertArrayHasKey('openrouter', app(EnricherManager::class)->available());

        Http::fake(['openrouter.test/*' => Http::response(['error' => 'sem crédito'], 402)]);

        try {
            (new OpenRouterEnricher)->enrich($this->article());
            $this->fail('Deveria ter falhado.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('OpenRouter HTTP 402: ', $e->getMessage());
        }

        config(['secmon.enricher.openrouter.api_key' => null]);
        $this->assertFalse((new OpenRouterEnricher)->isReady());
    }

    public function test_the_model_list_keeps_the_names_and_drops_odd_ids(): void
    {
        Http::fake(['openrouter.test/api/v1/models' => Http::response(['data' => [
            ['id' => 'openai/gpt-4o-mini', 'name' => 'OpenAI: GPT-4o-mini'],
            ['id' => 'anthropic/claude-sonnet-4.5', 'name' => 'Anthropic: Claude Sonnet 4.5'],
            ['id' => 'meta-llama/llama-3.3-70b-instruct:free'],
            ['id' => '<script>', 'name' => 'x'],
        ]])]);

        $this->assertSame([
            'anthropic/claude-sonnet-4.5' => 'Anthropic: Claude Sonnet 4.5',
            'meta-llama/llama-3.3-70b-instruct:free' => 'meta-llama/llama-3.3-70b-instruct:free',
            'openai/gpt-4o-mini' => 'OpenAI: GPT-4o-mini',
        ], (new OpenRouterEnricher)->namedModels());
    }
}
