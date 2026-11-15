<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Services\Enrichment\OpenAiEnricher;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** O driver OpenAI-compatível do enriquecimento (também serve a gateways locais: Groq, LM Studio, Ollama). */
class OpenAiEnricherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'secmon.enricher.openai.api_key' => 'sk-teste',
            'secmon.enricher.openai.base_url' => 'https://llm.test/v1/',
            'secmon.enricher.openai.model' => 'gpt-padrao',
            'secmon.enricher.openai.max_tokens' => 1234,
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

    private function reply(string $content, string $finish = 'stop'): void
    {
        Http::fake(['llm.test/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => $content], 'finish_reason' => $finish]],
        ])]);
    }

    public function test_enrich_sends_the_schema_bound_request_and_returns_the_parsed_record(): void
    {
        $this->reply(json_encode([
            'title' => 'Acme RCE explorada',
            'summary' => 'A Acme corrigiu a **CVE-2026-1234**.',
            'criticity' => 'critical',
            'cves' => ['CVE-2026-1234'],
        ]));

        $result = (new OpenAiEnricher)->enrich($this->article());

        $this->assertSame('openai', $result->driver);
        $this->assertSame('Acme RCE explorada', $result->title);
        $this->assertSame('critical', $result->criticity);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            $this->assertSame('https://llm.test/v1/chat/completions', $request->url());
            $this->assertSame(['Bearer sk-teste'], $request->header('Authorization'));
            $this->assertSame('gpt-padrao', $body['model']);
            $this->assertSame(1234, $body['max_tokens']);
            $this->assertSame('system', $body['messages'][0]['role']);
            $this->assertSame('user', $body['messages'][1]['role']);
            $this->assertSame('json_schema', $body['response_format']['type']);
            $this->assertTrue($body['response_format']['json_schema']['strict']);
            $this->assertSame('threat_intel_record', $body['response_format']['json_schema']['name']);

            return true;
        });
    }

    public function test_the_model_chosen_in_the_admin_wins_over_the_env_default(): void
    {
        Settings::setMany(['enricher.openai_model' => 'gpt-do-admin']);
        $this->reply(json_encode(['title' => 'T', 'summary' => 'S', 'criticity' => 'low']));

        (new OpenAiEnricher)->enrich($this->article());

        Http::assertSent(fn (Request $request) => $request->data()['model'] === 'gpt-do-admin');
    }

    public function test_a_provider_error_carries_the_status_and_the_start_of_the_body(): void
    {
        Http::fake(['llm.test/*' => Http::response(str_repeat('x', 500), 429)]);

        try {
            (new OpenAiEnricher)->enrich($this->article());
            $this->fail('devia ter lançado');
        } catch (RuntimeException $e) {
            $this->assertSame('OpenAI HTTP 429: '.str_repeat('x', 300), $e->getMessage());
        }
    }

    public function test_content_blocked_by_the_provider_filter_is_an_error(): void
    {
        $this->reply('{"title":"x"}', finish: 'content_filter');

        $this->assertSame('Conteúdo bloqueado pelo filtro do provedor.', $this->messageOfEnrich());
    }

    public function test_an_empty_reply_is_an_error(): void
    {
        $this->reply('   ');

        $this->assertSame('Resposta sem conteúdo utilizável.', $this->messageOfEnrich());
    }

    public function test_models_lists_the_provider_ids_sorted_and_without_non_strings(): void
    {
        Http::fake(['llm.test/v1/models' => Http::response(['data' => [['id' => 'gpt-b'], ['id' => 'gpt-a'], ['id' => 42], ['sem_id' => 1]]])]);

        $this->assertSame(['gpt-a', 'gpt-b'], (new OpenAiEnricher)->models());
        Http::assertSent(fn (Request $request) => $request->url() === 'https://llm.test/v1/models' && $request->header('Authorization') === ['Bearer sk-teste']);
    }

    public function test_models_fails_with_the_status_when_the_provider_refuses(): void
    {
        Http::fake(['llm.test/*' => Http::response('no', 401)]);

        $this->expectExceptionMessage('OpenAI HTTP 401');

        (new OpenAiEnricher)->models();
    }

    public function test_it_is_ready_with_a_key_or_with_a_gateway_that_is_not_openai(): void
    {
        $enricher = new OpenAiEnricher;

        config(['secmon.enricher.openai.api_key' => '', 'secmon.enricher.openai.base_url' => 'https://api.openai.com/v1']);
        $this->assertFalse($enricher->isReady());

        config(['secmon.enricher.openai.api_key' => 'sk-x']);
        $this->assertTrue($enricher->isReady());

        config(['secmon.enricher.openai.api_key' => '', 'secmon.enricher.openai.base_url' => 'http://ollama.local:11434/v1']);
        $this->assertTrue($enricher->isReady());
    }

    private function messageOfEnrich(): string
    {
        try {
            (new OpenAiEnricher)->enrich($this->article());
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        $this->fail('devia ter lançado');
    }
}
