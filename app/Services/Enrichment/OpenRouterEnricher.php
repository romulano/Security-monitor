<?php

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Driver OpenRouter: um gateway com os modelos de vários fornecedores (Anthropic, OpenAI,
 * Google, Meta, DeepSeek…) atrás de uma chave só (OPENROUTER_API_KEY), no protocolo da OpenAI.
 *
 * Igual ao OpenAiEnricher, com duas diferenças: o OpenRouter só manda a chamada a um provedor
 * que aceite a saída estruturada (`provider.require_parameters`, senão a resposta pode vir sem
 * o JSON do schema), e a chamada se identifica (HTTP-Referer e X-Title, o painel do OpenRouter
 * mostra de onde veio o gasto).
 */
class OpenRouterEnricher extends OpenAiEnricher
{
    protected const CONFIG = 'secmon.enricher.openrouter';

    protected const MODEL_SETTING = 'enricher.openrouter_model';

    protected const LABEL = 'OpenRouter';

    public function name(): string
    {
        return 'openrouter';
    }

    public function isReady(): bool
    {
        return filled(config(static::CONFIG.'.api_key'));
    }

    protected function headers(): array
    {
        return ['HTTP-Referer' => (string) config('app.url'), 'X-Title' => 'Security Monitor'];
    }

    protected function extraBody(): array
    {
        return ['provider' => ['require_parameters' => true]];
    }

    /**
     * Os modelos do OpenRouter com o nome de cada um ("Anthropic: Claude Sonnet 4.5"), por id.
     * O /models é público; com a chave, vai junto.
     *
     * @return array<string, string>
     */
    public function namedModels(): array
    {
        $response = Http::withToken((string) config(static::CONFIG.'.api_key'))
            ->withHeaders($this->headers())
            ->connectTimeout(3)
            ->timeout(8)
            ->get(rtrim((string) config(static::CONFIG.'.base_url'), '/').'/models');

        if (! $response->successful()) {
            throw new RuntimeException(static::LABEL.' HTTP '.$response->status());
        }

        $out = [];

        foreach ((array) $response->json('data') as $model) {
            if (is_array($model) && is_string($model['id'] ?? null) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/', $model['id'])) {
                $out[$model['id']] = is_string($model['name'] ?? null) && $model['name'] !== '' ? mb_substr($model['name'], 0, 80) : $model['id'];
            }
        }

        ksort($out);

        return $out;
    }
}
