<?php

namespace App\Services\Admin;

use App\Services\Enrichment\ClaudeEnricher;
use App\Services\Enrichment\EnricherManager;
use App\Services\Enrichment\HermesEnricher;
use App\Services\Enrichment\ModelCatalog;
use App\Services\IndexNow\IndexNow;
use App\Services\Sitemap;
use App\Support\Settings;

/** Admin → Configurações: crawler, driver de IA e o aviso aos buscadores (sitemap e IndexNow). */
class SettingsEditor
{
    public function __construct(
        private EnricherManager $enrichers,
        private ModelCatalog $catalog,
        private Sitemap $sitemap,
        private IndexNow $indexNow,
    ) {}

    public function page(): array
    {
        $hermes = (new HermesEnricher)->status();

        return [
            'values' => [
                'crawler.enabled' => Settings::bool('crawler.enabled'),
                'crawler.max_items_per_source' => Settings::int('crawler.max_items_per_source'),
                'crawler.timeout' => Settings::int('crawler.timeout'),
                'crawler.dedup_threshold' => Settings::float('crawler.dedup_threshold'),
                'crawler.user_agent' => (string) Settings::get('crawler.user_agent'),
                'enricher.enabled' => Settings::bool('enricher.enabled'),
                'enricher.driver' => (string) Settings::get('enricher.driver'),
                'enricher.claude_model' => (string) Settings::get('enricher.claude_model'),
                'enricher.claude_effort' => (string) Settings::get('enricher.claude_effort'),
                'enricher.openai_model' => (string) Settings::get('enricher.openai_model'),
                'enricher.openrouter_model' => (string) Settings::get('enricher.openrouter_model'),
                'enricher.hermes_url' => (string) Settings::get('enricher.hermes_url'),
                'enricher.hermes_model' => (string) Settings::get('enricher.hermes_model'),
                'enricher.hermes_effort' => (string) Settings::get('enricher.hermes_effort'),
                'indexnow.enabled' => Settings::bool(IndexNow::SETTING),
            ],
            'drivers' => collect($this->enrichers->available())
                ->map(fn ($d) => match ($d->name()) {
                    'hermes' => ['name' => 'hermes', 'ready' => $hermes['online'], 'note' => $hermes['online'] ? 'ponte no ar' : 'ponte fora do ar'],
                    'heuristic' => ['name' => 'heuristic', 'ready' => true, 'note' => 'sempre disponível'],
                    default => ['name' => $d->name(), 'ready' => $d->isReady(), 'note' => $d->isReady() ? 'credencial ok' : 'sem credencial no .env'],
                })
                ->values(),
            'hermes' => $hermes,
            'hermesTokenSet' => filled(config('secmon.enricher.hermes.token')),
            'efforts' => HermesEnricher::EFFORTS,
            'claudeEfforts' => ClaudeEnricher::EFFORTS,
            'models' => [
                'claude' => $this->catalog->claude((string) Settings::get('enricher.claude_model')),
                'openai' => $this->catalog->openai((string) Settings::get('enricher.openai_model')),
                'openrouter' => $this->catalog->openrouter((string) Settings::get('enricher.openrouter_model')),
                'hermes' => $this->catalog->hermes((string) Settings::get('enricher.hermes_model')),
            ],
            'sitemapUrls' => $this->sitemap->count(),
            'indexNow' => $this->indexNow->status(),
        ];
    }

    /** @param  array<string, mixed>  $data  os campos validados do formulário */
    public function save(array $data): void
    {
        Settings::setMany([
            'crawler.enabled' => (bool) ($data['crawler_enabled'] ?? false),
            'crawler.max_items_per_source' => (int) $data['crawler_max_items'],
            'crawler.timeout' => (int) $data['crawler_timeout'],
            'crawler.dedup_threshold' => (float) $data['crawler_dedup_threshold'],
            'crawler.user_agent' => $data['crawler_user_agent'],
            'enricher.enabled' => (bool) ($data['enricher_enabled'] ?? false),
            'enricher.driver' => $data['enricher_driver'],
            'enricher.claude_model' => $data['enricher_claude_model'] ?: config('secmon.enricher.claude.model'),
            // Vazio vale: a chamada vai sem o esforço e o modelo usa o padrão dele.
            'enricher.claude_effort' => (string) ($data['enricher_claude_effort'] ?? ''),
            'enricher.openai_model' => $data['enricher_openai_model'] ?: config('secmon.enricher.openai.model'),
            'enricher.openrouter_model' => ($data['enricher_openrouter_model'] ?? null) ?: config('secmon.enricher.openrouter.model'),
            'enricher.hermes_url' => rtrim((string) ($data['enricher_hermes_url'] ?: config('secmon.enricher.hermes.url')), '/'),
            // Vazio vale: o hermes cai no modelo/esforço do próprio hermes.conf.
            'enricher.hermes_model' => (string) ($data['enricher_hermes_model'] ?? ''),
            'enricher.hermes_effort' => (string) ($data['enricher_hermes_effort'] ?? ''),
            IndexNow::SETTING => (bool) ($data['indexnow_enabled'] ?? false),
        ]);
    }
}
