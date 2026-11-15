<?php

namespace App\Support;

use App\Models\PromptRevision;
use App\Services\Prompts\PromptCatalog;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Os trechos editáveis dos prompts que o hermes recebe. O padrão de cada um
 * fica em resources/prompts/<trecho>.txt; o admin pode trocá-lo, e a troca
 * vale na próxima chamada. As partes de que o código depende (formato da
 * resposta, etiquetas do texto de terceiros) ficam nos services, fora daqui.
 */
class Prompts
{
    private const CACHE_KEY = 'secmon.prompts';

    /** Etapas cuja chave já existia em Configurações: a aba Prompts liga e desliga a mesma. */
    private const STAGE_SETTINGS = [
        'analise' => 'enricher.enabled',
        'juiz-alertas' => 'alerts.ai_review_enabled',
    ];

    /** O texto em uso, com as variáveis trocadas: text('custom.tech', ['label' => 'SAP']). */
    public static function text(string $key, array $vars = []): string
    {
        $text = self::overrides()[$key] ?? self::default($key);

        foreach ($vars as $name => $value) {
            $text = str_replace('{'.$name.'}', (string) $value, $text);
        }

        return $text;
    }

    public static function default(string $key): string
    {
        return rtrim((string) file_get_contents(resource_path("prompts/{$key}.txt")));
    }

    /** Identifica a versão do padrão em que uma edição se baseou. */
    public static function defaultHash(string $key): string
    {
        return sha1(self::default($key));
    }

    /** @return array<string, string> trechos editados no admin; os outros usam o padrão */
    public static function overrides(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, 300, fn () => PromptRevision::query()
                ->current()
                ->whereNotNull('body')
                ->pluck('body', 'key')
                ->all());
        } catch (Throwable) {
            // Banco ainda não migrado (ex.: durante o build): vale o padrão.
            return [];
        }
    }

    /** A configuração que liga e desliga a etapa. */
    public static function stageKey(string $stage): string
    {
        return self::STAGE_SETTINGS[$stage] ?? 'prompts.'.$stage;
    }

    /**
     * Etapa ligada no admin. Sem escolha, vale o padrão do catálogo: ligada, a não
     * ser a que nasce desligada (`default_on`). Desligada, ela é pulada e a IA não é chamada.
     */
    public static function stageOn(string $stage): bool
    {
        $value = Settings::get(self::stageKey($stage));
        $default = PromptCatalog::STAGES[$stage]['default_on'] ?? true;

        return $value === null ? $default : (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
