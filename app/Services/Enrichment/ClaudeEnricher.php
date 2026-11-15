<?php

namespace App\Services\Enrichment;

use Anthropic\Client;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Messages\CacheControlEphemeral;
use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\WebFetchTool20260209;
use Anthropic\Messages\WebSearchTool20260209;
use Anthropic\Services\Messages\BatchesService;
use App\Models\Article;
use App\Models\NewsCase;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Driver Claude (Anthropic Messages API) usando o SDK oficial `anthropic-ai/sdk`.
 *
 * A saída é fixada por JSON Schema em `output_config.format`, então o modelo
 * devolve exatamente a estrutura de EnrichmentPrompt::schema().
 *
 * Com a pesquisa ligada, a chamada leva a busca e a leitura de páginas da
 * própria API (rodam na Anthropic, sem nada nesta máquina): as regras da
 * análise mandam procurar o boletim do fornecedor e o PR da correção. Aí o
 * schema vai no prompt, como no hermes, e não em `output_config.format`: a
 * API recusa o schema da análise junto com as ferramentas de busca ("The
 * compiled grammar is too large", 05/11, claude-sonnet-5).
 */
class ClaudeEnricher implements Enricher
{
    /** Os níveis de `output_config.effort` da Messages API. */
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** Quantas vezes a resposta pausada (`pause_turn`) é retomada antes de desistir. */
    private const MAX_RESUMES = 3;

    public function __construct(private ?Client $client = null) {}

    public function name(): string
    {
        return 'claude';
    }

    public function isReady(): bool
    {
        return filled(config('secmon.enricher.claude.api_key'));
    }

    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: (string) config('secmon.enricher.claude.api_key'),
            baseUrl: (string) config('secmon.enricher.claude.base_url'),
        );
    }

    /**
     * Modelos que a chave enxerga, pela Models API (mais novos primeiro).
     *
     * @return array<string, string> id => nome de exibição
     */
    public function models(): array
    {
        if (! $this->isReady()) {
            throw new RuntimeException('ANTHROPIC_API_KEY não configurada.');
        }

        // Sem novas tentativas: é a tela de configurações esperando.
        $page = $this->client()->models->list(limit: 100, requestOptions: ['maxRetries' => 0]);

        $models = [];

        foreach ($page->getItems() as $model) {
            $models[$model->id] = $model->displayName;
        }

        return $models;
    }

    public function enrich(Article $article, ?NewsCase $existing = null): EnrichmentResult
    {
        if (! $this->isReady()) {
            throw new RuntimeException('ANTHROPIC_API_KEY não configurada.');
        }

        $model = self::model();

        return EnrichmentPrompt::withPatch($article, $existing, fn (bool $patch) => $this->research($article, $existing, $model, $patch));
    }

    /** Os lotes da API (AnalysisBatch), com a mesma chave e o mesmo endereço da análise. */
    public function batches(): BatchesService
    {
        return $this->client()->messages->batches;
    }

    /** O modelo de Admin → Configurações, ou o do .env. */
    public static function model(): string
    {
        return (string) (Settings::get('enricher.claude_model') ?: config('secmon.enricher.claude.model'));
    }

    /**
     * O pedido da análise de um artigo para o lote da API (AnalysisBatch): o
     * mesmo que enrich() manda, com a pesquisa se ligada e com trechos quando
     * a matéria aceita. Sem a refação sem pesquisa: no lote, a falha cai no
     * caminho normal.
     *
     * @return array<string, mixed> os argumentos nomeados de messages->create()
     */
    public function batchRequest(Article $article, ?NewsCase $existing): array
    {
        $patch = EnrichmentPrompt::patchMode($existing);

        return $this->request(self::model(), [['role' => 'user', 'content' => EnrichmentPrompt::user($article, $existing, $patch)]], self::tools(), $patch);
    }

    /** O texto final que o lote devolveu para um artigo (finalText()), lido como o da chamada direta. */
    public function fromText(string $text, Article $article, ?NewsCase $existing): EnrichmentResult
    {
        return EnrichmentNormalizer::fromJson($text, $article, $this->name(), $existing);
    }

    /** A análise com a pesquisa na web, se ligada, e sem ela quando a pesquisa não dá certo. */
    private function research(Article $article, ?NewsCase $existing, string $model, bool $patch): EnrichmentResult
    {
        $tools = self::tools();

        if ($tools === []) {
            return $this->analyze($article, $existing, $model, [], $patch);
        }

        // A análise sai só com o artigo, em vez de cair na heurística, quando a
        // pesquisa não dá certo: a API recusou as ferramentas (modelo sem a
        // busca), ou a resposta com pesquisa não trouxe JSON legível (sem o
        // formato fixo, ~4% das respostas vieram com JSON quebrado em 05/11)
        // ou não terminou. Sem pesquisa, o formato fixo garante o JSON.
        try {
            return $this->analyze($article, $existing, $model, $tools, $patch);
        } catch (BadRequestException $e) {
            Log::warning('Análise Claude: a API recusou a pesquisa na web; refeita sem ela', ['modelo' => $model, 'erro' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            if (! self::researchFailure($e)) {
                throw $e;
            }

            Log::warning('Análise Claude: a resposta com pesquisa não deu para ler; refeita sem ela', ['modelo' => $model, 'artigo' => $article->id, 'erro' => $e->getMessage()]);
        }

        return $this->analyze($article, $existing, $model, [], $patch);
    }

    /** @param  list<WebSearchTool20260209|WebFetchTool20260209>  $tools */
    private function analyze(Article $article, ?NewsCase $existing, string $model, array $tools, bool $patch = false): EnrichmentResult
    {
        $messages = [['role' => 'user', 'content' => EnrichmentPrompt::user($article, $existing, $patch)]];
        $message = $this->send($model, $messages, $tools, $patch, $article);

        // Com a busca, a API pode pausar a resposta longa: devolvê-la como está retoma de onde parou.
        for ($i = 0; $message->stopReason === 'pause_turn' && $i < self::MAX_RESUMES; $i++) {
            $messages[] = ['role' => 'assistant', 'content' => $message->content];
            $message = $this->send($model, $messages, $tools, $patch, $article);
        }

        return $this->fromText(self::finalText($message), $article, $existing);
    }

    /**
     * O texto final da resposta (o JSON da análise). Parada que não é resposta
     * (pesquisa pausada, corte no teto, recusa) vira erro com o motivo.
     *
     * @throws RuntimeException
     */
    public static function finalText(Message $message): string
    {
        if ($message->stopReason === 'pause_turn') {
            throw new RuntimeException('Claude não terminou a pesquisa na web (pause_turn '.self::MAX_RESUMES.' vezes).');
        }

        // JSON cortado no meio não lê; o motivo claro é o limite, não o JSON (Admin → Coletas).
        if ($message->stopReason === 'max_tokens') {
            throw new RuntimeException('Claude cortou a resposta no limite de tokens (max_tokens, '.(int) config('secmon.enricher.claude.max_tokens').').');
        }

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('Claude recusou a análise deste artigo ('.
                ($message->stopDetails->category ?? 'sem categoria').').');
        }

        // Só o texto depois da última busca: o que vem antes dela é o modelo
        // dizendo o que vai procurar, não o JSON.
        $text = '';

        foreach ($message->content as $block) {
            $text = $block instanceof TextBlock ? $text.$block->text : '';
        }

        return $text;
    }

    /** Falhas da resposta com pesquisa que a mesma análise sem pesquisa resolve. */
    private static function researchFailure(RuntimeException $e): bool
    {
        return Str::startsWith($e->getMessage(), [
            'JSON inválido devolvido pelo modelo',
            'Resposta do modelo não contém JSON',
            'Resposta vazia do modelo',
            'Claude não terminou a pesquisa na web',
        ]);
    }

    /** @param  list<WebSearchTool20260209|WebFetchTool20260209>  $tools */
    private function send(string $model, array $messages, array $tools, bool $patch, Article $article): Message
    {
        $research = $tools !== [];
        $started = microtime(true);

        $message = $this->client()->messages->create(...$this->request($model, $messages, $tools, $patch));

        self::logUsage($message, $article, $model, $research, $patch, microtime(true) - $started);

        return $message;
    }

    /**
     * Os argumentos de messages->create(), os mesmos no pedido do lote.
     *
     * @param  list<WebSearchTool20260209|WebFetchTool20260209>  $tools
     * @return array<string, mixed>
     */
    private function request(string $model, array $messages, array $tools, bool $patch): array
    {
        $research = $tools !== [];

        return array_filter([
            'maxTokens' => (int) config('secmon.enricher.claude.max_tokens'),
            'messages' => $messages,
            'model' => $model,
            'outputConfig' => $this->outputConfig(format: ! $research, patch: $patch) ?: null,
            // As regras num bloco com cache (system()): também no lote, que aceita o cache.
            'system' => self::system($research, $patch),
            'tools' => $research ? $tools : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Uma linha por chamada com o que ela gastou: mede se o teto da resposta
     * (`max_tokens`) está perto de ser batido e quanto dele vai no raciocínio,
     * e se a chamada cabe no tempo do job (300 s).
     */
    private static function logUsage(Message $message, Article $article, string $model, bool $research, bool $patch, float $seconds): void
    {
        // Campo que a resposta não trouxe fica sem valor no SDK (ler dá erro):
        // isset() antes de cada um, e o registro nunca derruba a análise.
        try {
            $usage = isset($message->usage) ? $message->usage : null;
            $tools = isset($usage->serverToolUse) ? $usage->serverToolUse : null;
            $details = isset($usage->outputTokensDetails) ? $usage->outputTokensDetails : null;

            Log::info('Análise Claude: uso', [
                'artigo' => $article->id,
                'modelo' => $model,
                'pesquisa' => $research,
                'trechos' => $patch,
                'parada' => $message->stopReason ?? null,
                'teto' => (int) config('secmon.enricher.claude.max_tokens'),
                'saida' => $usage->outputTokens ?? null,
                'raciocinio' => $details->thinkingTokens ?? null,
                'entrada' => $usage->inputTokens ?? null,
                'cache_lido' => $usage->cacheReadInputTokens ?? null,
                'cache_gravado' => $usage->cacheCreationInputTokens ?? null,
                'buscas' => $tools->webSearchRequests ?? null,
                'paginas' => $tools->webFetchRequests ?? null,
                'segundos' => round($seconds, 1),
            ]);
        } catch (Throwable $e) {
            Log::warning('Análise Claude: uso não registrado', ['artigo' => $article->id, 'erro' => $e->getMessage()]);
        }
    }

    /**
     * As regras da análise (com o schema, na chamada com pesquisa) num bloco
     * com cache: são iguais em todo artigo (só mudam com a pesquisa e com os
     * trechos), então do segundo artigo em diante, dentro de 5 minutos, a API
     * as lê do cache, a 10% do preço da entrada. As ferramentas vêm antes no
     * prefixo e também entram no cache. Nada que muda por artigo vai aqui.
     *
     * @return list<TextBlockParam>
     */
    public static function system(bool $research, bool $patch = false): array
    {
        return [TextBlockParam::with(
            text: $research ? HermesEnricher::system($patch) : EnrichmentPrompt::system($patch),
            cacheControl: CacheControlEphemeral::with(),
        )];
    }

    /**
     * Busca e leitura de páginas da API, com teto de usos por artigo (cada
     * busca é cobrada à parte). Vazio com a pesquisa desligada. As classes do
     * SDK, não arrays: num array cru o `maxUses` iria à API com esse nome, e a
     * API recusaria a chamada (e a análise sairia sem pesquisa).
     *
     * @return list<WebSearchTool20260209|WebFetchTool20260209>
     */
    public static function tools(): array
    {
        if (! config('secmon.enricher.claude.research')) {
            return [];
        }

        return [
            WebSearchTool20260209::with(maxUses: (int) config('secmon.enricher.claude.research_searches')),
            WebFetchTool20260209::with(
                // Sem teto, a página inteira entra como entrada; 0 = sem teto.
                maxContentTokens: (int) config('secmon.enricher.claude.research_fetch_tokens') ?: null,
                maxUses: (int) config('secmon.enricher.claude.research_fetches'),
            ),
        ];
    }

    /**
     * O esforço vem de Admin → Configurações; vazio fica sem o parâmetro e vale
     * o padrão do modelo. Sem `format` na chamada com pesquisa (ver a classe).
     *
     * @return array<string, mixed>
     */
    public function outputConfig(bool $format = true, bool $patch = false): array
    {
        $effort = (string) Settings::get('enricher.claude_effort');

        return array_filter([
            'effort' => in_array($effort, self::EFFORTS, true) ? $effort : null,
            'format' => $format ? ['type' => 'json_schema', 'schema' => EnrichmentPrompt::schema($patch)] : null,
        ]);
    }
}
