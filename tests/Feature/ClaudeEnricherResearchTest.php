<?php

namespace Tests\Feature;

use Anthropic\Client;
use App\Models\Article;
use App\Services\Enrichment\ClaudeEnricher;
use App\Support\Settings;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Tests\TestCase;

/** A análise pelo Claude da API pesquisa na web com a busca e a leitura de páginas da própria API. */
class ClaudeEnricherResearchTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> corpos dos pedidos que a API recebeu */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['secmon.enricher.claude.api_key' => 'chave-de-teste']);
        Settings::flush();
        Settings::setMany(['enricher.claude_model' => 'claude-sonnet-5', 'enricher.claude_effort' => 'medium']);
    }

    public function test_the_analysis_searches_the_web_and_reads_only_the_final_answer(): void
    {
        $enricher = $this->enricher([
            $this->message('end_turn', [
                ['type' => 'text', 'text' => 'Vou procurar o boletim do fornecedor.'],
                ...$this->search(),
                ['type' => 'text', 'text' => json_encode(['title' => 'Laravel flaw rated Low', 'summary' => 'Vendor rates it **Low**.', 'criticity' => 'low'])],
            ]),
        ]);

        $result = $enricher->enrich($this->article());

        $this->assertSame('Laravel flaw rated Low', $result->title);
        $this->assertSame('low', $result->criticity);

        // Os nomes da API (max_uses, max_content_tokens), não os do SDK (maxUses): com o nome errado a API recusaria a chamada.
        $fields = ['name', 'type', 'max_uses', 'max_content_tokens'];
        $this->assertSame([
            ['name' => 'web_search', 'type' => 'web_search_20260209', 'max_uses' => 5],
            ['name' => 'web_fetch', 'type' => 'web_fetch_20260209', 'max_content_tokens' => 12000, 'max_uses' => 8],
        ], array_map(fn ($tool) => array_intersect_key($tool, array_flip($fields)), $this->sent[0]['tools']));
        $this->assertSame([], array_diff(array_merge(...array_map('array_keys', $this->sent[0]['tools'])), $fields));
        // Schema no prompt, não em output_config.format: a API recusa os dois juntos ("compiled grammar is too large").
        $this->assertArrayNotHasKey('format', $this->sent[0]['output_config']);
        $this->assertSame('medium', $this->sent[0]['output_config']['effort']);
        // As regras num bloco com cache: iguais em todo artigo, lidas do cache a partir do segundo.
        $this->assertCount(1, $this->sent[0]['system']);
        $this->assertSame('text', $this->sent[0]['system'][0]['type']);
        $this->assertStringContainsString('JSON Schema', $this->sent[0]['system'][0]['text']);
        $this->assertSame(['type' => 'ephemeral'], $this->sent[0]['system'][0]['cache_control']);
    }

    public function test_the_page_ceiling_can_be_turned_off_and_the_rules_are_the_same_for_every_article(): void
    {
        config(['secmon.enricher.claude.research_fetch_tokens' => 0]);
        $done = fn () => $this->message('end_turn', [['type' => 'text', 'text' => json_encode(['title' => 'Done', 'summary' => 'Done.'])]]);
        $enricher = $this->enricher([$done(), $done()]);

        $enricher->enrich($this->article());
        $other = $this->article();
        $other->forceFill(['url' => 'https://example.com/outro', 'content' => 'Another advisory.']);
        $enricher->enrich($other);

        $this->assertArrayNotHasKey('max_content_tokens', $this->sent[0]['tools'][1]);
        // Prefixo igual byte a byte nos dois artigos: é o que deixa o cache valer.
        $this->assertSame($this->sent[0]['system'], $this->sent[1]['system']);
        $this->assertSame($this->sent[0]['tools'], $this->sent[1]['tools']);
        $this->assertNotSame($this->sent[0]['messages'], $this->sent[1]['messages']);
    }

    public function test_a_paused_answer_is_sent_back_as_is_to_resume(): void
    {
        $enricher = $this->enricher([
            $this->message('pause_turn', $this->search()),
            $this->message('end_turn', [['type' => 'text', 'text' => json_encode(['title' => 'Resumed', 'summary' => 'Done.'])]]),
        ]);

        $this->assertSame('Resumed', $enricher->enrich($this->article())->title);

        $this->assertCount(2, $this->sent);
        $messages = $this->sent[1]['messages'];
        $this->assertSame(['user', 'assistant'], array_column($messages, 'role'));
        $this->assertSame(['server_tool_use', 'web_search_tool_result'], array_column($messages[1]['content'], 'type'));
    }

    public function test_a_search_that_never_ends_falls_back_to_the_analysis_without_it(): void
    {
        $enricher = $this->enricher([
            ...array_map(fn () => $this->message('pause_turn', $this->search()), range(1, 4)),
            $this->message('end_turn', [['type' => 'text', 'text' => json_encode(['title' => 'Without research', 'summary' => 'From the article.'])]]),
        ]);

        $this->assertSame('Without research', $enricher->enrich($this->article())->title);
        $this->assertCount(5, $this->sent);
        $this->assertArrayNotHasKey('tools', $this->sent[4]);
    }

    public function test_an_unreadable_answer_with_research_is_redone_without_it(): void
    {
        // Sem o formato fixo, o modelo às vezes quebra o JSON (aspas sem escape): 3 de 80 em 05/11.
        $enricher = $this->enricher([
            $this->message('end_turn', [...$this->search(), ['type' => 'text', 'text' => '{"title": "APT29 "Poisoned Source" campaign", "summary": "x"}']]),
            $this->message('end_turn', [['type' => 'text', 'text' => json_encode(['title' => 'APT29 Poisoned Source campaign', 'summary' => 'From the article.'])]]),
        ]);

        $this->assertSame('APT29 Poisoned Source campaign', $enricher->enrich($this->article())->title);
        $this->assertArrayHasKey('tools', $this->sent[0]);
        $this->assertArrayNotHasKey('tools', $this->sent[1]);
        $this->assertSame('json_schema', $this->sent[1]['output_config']['format']['type']);
    }

    public function test_an_unreadable_answer_without_research_is_still_an_error(): void
    {
        config(['secmon.enricher.claude.research' => false]);

        $enricher = $this->enricher([$this->message('end_turn', [['type' => 'text', 'text' => 'not json']])]);

        $this->expectException(RuntimeException::class);

        $enricher->enrich($this->article());
    }

    public function test_when_the_api_refuses_the_search_the_analysis_runs_without_it(): void
    {
        $enricher = $this->enricher([
            new Response(400, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'web_search is not supported on this model']])),
            $this->message('end_turn', [['type' => 'text', 'text' => json_encode(['title' => 'Without research', 'summary' => 'From the article.'])]]),
        ]);

        $this->assertSame('Without research', $enricher->enrich($this->article())->title);
        $this->assertArrayHasKey('tools', $this->sent[0]);
        $this->assertArrayNotHasKey('tools', $this->sent[1]);
        $this->assertSame('json_schema', $this->sent[1]['output_config']['format']['type'], 'sem pesquisa, volta o formato fixo');
    }

    public function test_the_research_can_be_turned_off(): void
    {
        config(['secmon.enricher.claude.research' => false]);

        $enricher = $this->enricher([
            $this->message('end_turn', [['type' => 'text', 'text' => json_encode(['title' => 'Plain', 'summary' => 'From the article.'])]]),
        ]);

        $enricher->enrich($this->article());

        $this->assertArrayNotHasKey('tools', $this->sent[0]);
        $this->assertSame('json_schema', $this->sent[0]['output_config']['format']['type']);
        // Sem pesquisa as regras também vão com cache, e sem o schema no texto (ele vai no format).
        $this->assertSame(['type' => 'ephemeral'], $this->sent[0]['system'][0]['cache_control']);
        $this->assertStringNotContainsString('JSON Schema', $this->sent[0]['system'][0]['text']);
        $this->assertSame([], ClaudeEnricher::tools());
    }

    /** @param  list<ResponseInterface>  $responses */
    public function test_the_answer_has_room_for_the_research_and_each_call_logs_what_it_spent(): void
    {
        Log::spy();
        $enricher = $this->enricher([$this->message('end_turn', [['type' => 'text', 'text' => json_encode(['title' => 'Done', 'summary' => 'Done.'])]])]);

        $enricher->enrich($this->article());

        // 16 mil + 50% (06/11): com a pesquisa, algumas análises passavam de 16 mil e caíam na heurística.
        $this->assertSame(24000, $this->sent[0]['max_tokens']);
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'Análise Claude: uso'
            && $context['teto'] === 24000 && $context['saida'] === 10 && $context['entrada'] === 10
            && $context['pesquisa'] === true && $context['parada'] === 'end_turn');
    }

    public function test_a_cut_answer_says_which_ceiling_it_hit(): void
    {
        config(['secmon.enricher.claude.research' => false]);
        $enricher = $this->enricher([$this->message('max_tokens', [['type' => 'text', 'text' => '{"title": "Cut']])]);

        $this->expectExceptionMessage('Claude cortou a resposta no limite de tokens (max_tokens, 24000).');

        $enricher->enrich($this->article());
    }

    private function enricher(array $responses): ClaudeEnricher
    {
        $transporter = new class($responses, $this->sent) implements ClientInterface
        {
            public function __construct(private array $responses, private array &$sent) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = json_decode((string) $request->getBody(), true);

                return array_shift($this->responses) ?? throw new RuntimeException('pedido a mais à API');
            }
        };

        return new ClaudeEnricher(new Client(
            apiKey: 'chave-de-teste',
            baseUrl: 'https://api.anthropic.test',
            requestOptions: ['transporter' => $transporter, 'maxRetries' => 0],
        ));
    }

    /** @param  list<array<string, mixed>>  $content */
    private function message(string $stop, array $content): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'content' => $content,
            'stop_reason' => $stop,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ]));
    }

    /** @return list<array<string, mixed>> uma busca e o resultado dela */
    private function search(): array
    {
        return [
            ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'GHSA-63hm-g96r-2m2q']],
            ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => [
                ['type' => 'web_search_result', 'url' => 'https://github.com/laravel/framework/security/advisories/GHSA-63hm-g96r-2m2q', 'title' => 'Task Output Injection', 'encrypted_content' => 'abc', 'page_age' => null],
            ]],
        ];
    }

    private function article(): Article
    {
        return new Article([
            'url' => 'https://www.cert.ssi.gouv.fr/avis/CERTFR-2026-AVI-1457/',
            'source_host' => 'cert.ssi.gouv.fr',
            'original_title' => 'Vulnérabilité dans Laravel',
            'content' => 'Une vulnérabilité permet une exécution de code arbitraire à distance. Laravel versions antérieures à 13.40.0.',
        ]);
    }
}
