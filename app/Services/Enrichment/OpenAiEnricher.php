<?php

namespace App\Services\Enrichment;

use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Review\ReportReviewer;
use App\Services\Review\ReviewVerdict;
use App\Support\Settings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Driver OpenAI-compatível (`/chat/completions`). Funciona com a API da OpenAI
 * e com qualquer gateway que fale o mesmo protocolo — basta apontar
 * OPENAI_BASE_URL. A estrutura é fixada por `response_format: json_schema`.
 *
 * O OpenRouter (OpenRouterEnricher) fala o mesmo protocolo: estende este driver
 * trocando a configuração, os cabeçalhos e o que vai a mais no corpo.
 *
 * Fala também com o revisor do relatório (ChatDriver): o parecer sobre o
 * rascunho e a refação que ele pede vão para o mesmo provedor da análise.
 */
class OpenAiEnricher implements ChatDriver
{
    /** A configuração do driver em config/secmon.php e o Setting do modelo escolhido no admin. */
    protected const CONFIG = 'secmon.enricher.openai';

    protected const MODEL_SETTING = 'enricher.openai_model';

    /** Como o provedor aparece nos erros ("OpenAI HTTP 429…"). */
    protected const LABEL = 'OpenAI';

    public function name(): string
    {
        return 'openai';
    }

    public function isReady(): bool
    {
        return filled(config(static::CONFIG.'.api_key'))
            || ! str_contains((string) config(static::CONFIG.'.base_url'), 'api.openai.com');
    }

    /** @return array<string, string> cabeçalhos a mais de cada chamada */
    protected function headers(): array
    {
        return [];
    }

    /** @return array<string, mixed> o que vai a mais no corpo do /chat/completions */
    protected function extraBody(): array
    {
        return [];
    }

    /**
     * IDs que o `/models` do provedor devolve — OpenAI, Groq, LM Studio e
     * Ollama expõem o mesmo endpoint.
     *
     * @return list<string>
     */
    public function models(): array
    {
        $base = rtrim((string) config(static::CONFIG.'.base_url'), '/');

        $response = Http::withToken((string) config(static::CONFIG.'.api_key'))
            ->withHeaders($this->headers())
            ->connectTimeout(3)
            ->timeout(5)
            ->get($base.'/models');

        if (! $response->successful()) {
            throw new RuntimeException(static::LABEL.' HTTP '.$response->status());
        }

        $ids = array_values(array_filter(Arr::pluck($response->json('data') ?? [], 'id'), 'is_string'));
        sort($ids);

        return $ids;
    }

    public function enrich(Article $article, ?NewsCase $existing = null): EnrichmentResult
    {
        $content = $this->complete(
            EnrichmentPrompt::system(),
            EnrichmentPrompt::user($article, $existing),
            $this->chatTimeout(),
            EnrichmentPrompt::schema(),
        );

        return EnrichmentNormalizer::fromJson($content, $article, $this->name());
    }

    /**
     * A refação que o revisor do relatório pediu: a mesma mensagem da análise,
     * com o pedido de ajuste no fim (o escopo, os problemas e o rascunho anterior).
     */
    public function revise(Article $article, ?NewsCase $existing, EnrichmentResult $draft, string $mode, ReviewVerdict $verdict): EnrichmentResult
    {
        $content = $this->complete(
            EnrichmentPrompt::system(),
            EnrichmentPrompt::user($article, $existing).ReportReviewer::redoRequest($draft, $mode, $verdict),
            $this->chatTimeout(),
            EnrichmentPrompt::schema(),
        );

        return EnrichmentNormalizer::fromJson($content, $article, $this->name());
    }

    /** O parecer do revisor: resposta em texto, sem o schema da análise. */
    public function chat(string $system, string $user, int $timeout): string
    {
        return $this->complete($system, $user, $timeout);
    }

    public function chatTimeout(): int
    {
        return (int) config(static::CONFIG.'.timeout');
    }

    /** A segunda tentativa da requisição (`retry`) cabe no mesmo prazo do job. */
    public function chatReserve(): int
    {
        return $this->chatTimeout() + 2;
    }

    /**
     * Uma chamada ao `/chat/completions`, com o texto da resposta. Com `$schema`,
     * a estrutura é fixada por `response_format: json_schema` (a análise e a
     * refação); sem ele, a resposta vem em texto (o parecer do revisor).
     *
     * O tempo limite da requisição é o tempo da IA: aqui não há `timeout_seconds`
     * como no hermes, é o provedor que responde.
     *
     * @param  ?array<string, mixed>  $schema
     */
    protected function complete(string $system, string $user, int $timeout, ?array $schema = null): string
    {
        $base = rtrim((string) config(static::CONFIG.'.base_url'), '/');
        $model = (string) (Settings::get(static::MODEL_SETTING) ?: config(static::CONFIG.'.model'));

        $response = Http::withToken((string) config(static::CONFIG.'.api_key'))
            ->withHeaders($this->headers())
            ->timeout($timeout)
            ->retry(2, 1500, throw: false)
            ->post($base.'/chat/completions', $this->extraBody() + array_filter([
                'model' => $model,
                'max_tokens' => (int) config(static::CONFIG.'.max_tokens'),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => $schema === null ? null : [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'threat_intel_record',
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
            ], fn ($value) => $value !== null));

        if (! $response->successful()) {
            throw new RuntimeException(
                static::LABEL.' HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300)
            );
        }

        $body = $response->json();
        $content = Arr::get($body, 'choices.0.message.content');

        if (Arr::get($body, 'choices.0.finish_reason') === 'content_filter') {
            throw new RuntimeException('Conteúdo bloqueado pelo filtro do provedor.');
        }

        if (Arr::get($body, 'choices.0.finish_reason') === 'length') {
            throw new RuntimeException(static::LABEL.' cortou a resposta no limite de tokens (max_tokens).');
        }

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Resposta sem conteúdo utilizável.');
        }

        return $content;
    }
}
