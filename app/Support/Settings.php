<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Configuração de runtime editável pelo admin.
 *
 * A tabela `settings` guarda apenas o que foi explicitamente alterado; o resto
 * cai no default de config/secmon.php. Assim é possível ligar/desligar bots
 * e trocar o driver de IA sem recriar container nem editar .env.
 */
class Settings
{
    private const CACHE_KEY = 'secmon.settings';

    /** Chave => caminho do default em config/secmon.php. */
    public const DEFAULTS = [
        'crawler.enabled' => 'secmon.crawler.enabled',
        'crawler.max_items_per_source' => 'secmon.crawler.max_items_per_source',
        'crawler.timeout' => 'secmon.crawler.timeout',
        'crawler.dedup_threshold' => 'secmon.crawler.dedup_threshold',
        'crawler.user_agent' => 'secmon.crawler.user_agent',
        'enricher.enabled' => 'secmon.enricher.enabled',
        'enricher.driver' => 'secmon.enricher.driver',
        'enricher.claude_model' => 'secmon.enricher.claude.model',
        'enricher.claude_effort' => 'secmon.enricher.claude.effort',
        'enricher.openai_model' => 'secmon.enricher.openai.model',
        'enricher.openrouter_model' => 'secmon.enricher.openrouter.model',
        'enricher.hermes_url' => 'secmon.enricher.hermes.url',
        'enricher.hermes_model' => 'secmon.enricher.hermes.model',
        'enricher.hermes_effort' => 'secmon.enricher.hermes.effort',
        'alerts.enabled' => 'secmon.alerts.enabled',
        'alerts.realtime_enabled' => 'secmon.alerts.realtime_enabled',
        'alerts.digest_enabled' => 'secmon.alerts.digest_enabled',
        'alerts.discovery_enabled' => 'secmon.alerts.discovery_enabled',
        'alerts.digest_default_time' => 'secmon.alerts.digest_default_time',
        'alerts.default_timezone' => 'secmon.alerts.default_timezone',
        'alerts.max_per_hour' => 'secmon.alerts.max_per_hour',
        'alerts.min_score' => 'secmon.alerts.min_score',
        'alerts.ai_review_enabled' => 'secmon.alerts.ai_review_enabled',
        'alerts.ai_review_threshold' => 'secmon.alerts.ai_review_threshold',
        'alerts.discovery_every_days' => 'secmon.alerts.discovery_every_days',
        'alerts.consent_version' => 'secmon.alerts.consent_version',
        'github.ai_threshold' => 'secmon.github.ai_threshold',
        'monitoring.enabled' => 'secmon.monitoring.enabled',
        'indexnow.enabled' => 'secmon.indexnow.enabled',
    ];

    public static function all(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, 300, fn () => Setting::query()->pluck('value', 'key')->all());
        } catch (Throwable) {
            // Banco ainda não migrado (ex.: durante o build) — cai no default.
            return [];
        }
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        $stored = self::all();

        if (array_key_exists($key, $stored) && $stored[$key] !== null) {
            $value = $stored[$key];

            // O jsonb guarda escalares embrulhados para manter o tipo.
            return is_array($value) && array_key_exists('v', $value) ? $value['v'] : $value;
        }

        if ($fallback !== null) {
            return $fallback;
        }

        return isset(self::DEFAULTS[$key]) ? config(self::DEFAULTS[$key]) : null;
    }

    public static function bool(string $key): bool
    {
        return filter_var(self::get($key), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function float(string $key): float
    {
        return (float) self::get($key);
    }

    public static function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
        Cache::forget(self::CACHE_KEY);
    }

    public static function setMany(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
