<?php

namespace App\Http\Requests\Admin\Settings;

use App\Http\Requests\Request;
use App\Services\Enrichment\ClaudeEnricher;
use App\Services\Enrichment\HermesEnricher;

class UpdateSettingsRequest extends Request
{
    private const MODEL_NAME = 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/';

    public function rules(): array
    {
        return [
            'crawler_enabled' => ['nullable', 'boolean'],
            'crawler_max_items' => ['required', 'integer', 'min:1', 'max:100'],
            'crawler_timeout' => ['required', 'integer', 'min:5', 'max:120'],
            'crawler_dedup_threshold' => ['required', 'numeric', 'min:0.3', 'max:0.95'],
            'crawler_user_agent' => ['required', 'string', 'max:255'],
            'enricher_enabled' => ['nullable', 'boolean'],
            'enricher_driver' => ['required', 'in:claude,openai,openrouter,hermes,heuristic'],
            // Os selects oferecem a lista do provedor, mas o valor chega como
            // texto livre num POST: só aceita o formato de um nome de modelo.
            'enricher_claude_model' => ['nullable', 'string', 'max:120', self::MODEL_NAME],
            'enricher_claude_effort' => ['nullable', 'in:'.implode(',', ClaudeEnricher::EFFORTS)],
            'enricher_openai_model' => ['nullable', 'string', 'max:120', self::MODEL_NAME],
            'enricher_openrouter_model' => ['nullable', 'string', 'max:120', self::MODEL_NAME],
            'enricher_hermes_url' => ['nullable', 'url:http,https', 'max:255'],
            'enricher_hermes_model' => ['nullable', 'string', 'max:120', self::MODEL_NAME],
            'enricher_hermes_effort' => ['nullable', 'in:'.implode(',', HermesEnricher::EFFORTS)],
            'indexnow_enabled' => ['nullable', 'boolean'],
        ];
    }
}
