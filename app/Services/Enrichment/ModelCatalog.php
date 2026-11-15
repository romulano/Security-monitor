<?php

namespace App\Services\Enrichment;

use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opções dos selects de modelo em Admin → Configurações.
 *
 * Cada provedor é consultado ao vivo quando dá (Models API da Anthropic,
 * `/models` do provedor OpenAI-compatível e do OpenRouter, `/v1/models` do hermes); sem
 * credencial ou fora do ar, cai numa lista fixa. O modelo salvo hoje sempre
 * aparece, para não sumir só porque saiu da lista.
 *
 * Formato: ['groups' => [rótulo => [valor => texto]], 'note' => string]. Grupo
 * com rótulo '' vira opções soltas, sem <optgroup>.
 */
class ModelCatalog
{
    private const TTL = 600;

    /** Provedor fora do ar: não insistir (e esperar o timeout) a cada página. */
    private const FAILURE_TTL = 60;

    public function claude(string $current): array
    {
        $driver = new ClaudeEnricher;
        $live = $driver->isReady() ? $this->live('claude', fn () => $driver->models()) : null;

        return $this->withCurrent([
            'groups' => ['' => $this->labelled($live ?? config('secmon.enricher.claude.models'))],
            'note' => $live
                ? 'Lista da Models API da Anthropic.'
                : 'Lista fixa. Com ANTHROPIC_API_KEY no .env, a lista vem da Models API.',
        ], $current);
    }

    public function openai(string $current): array
    {
        $driver = new OpenAiEnricher;
        $base = (string) config('secmon.enricher.openai.base_url');
        $live = $driver->isReady() ? $this->live('openai:'.md5($base), fn () => $driver->models()) : null;
        $models = $live ?? array_filter([(string) config('secmon.enricher.openai.model')]);

        return $this->withCurrent([
            'groups' => ['' => array_combine($models, $models)],
            'note' => $live
                ? 'Lista do /models de '.parse_url($base, PHP_URL_HOST).'.'
                : 'Sem lista do provedor: com OPENAI_API_KEY (ou OPENAI_BASE_URL de um gateway local) no .env, os modelos aparecem aqui.',
        ], $current);
    }

    /** O OpenRouter: centenas de modelos, agrupados pelo fornecedor (o começo do id, antes da barra). */
    public function openrouter(string $current): array
    {
        $live = $this->live('openrouter', fn () => (new OpenRouterEnricher)->namedModels());
        $models = $live ?: config('secmon.enricher.openrouter.models');
        $groups = [];

        foreach ($models as $id => $name) {
            $vendor = str_contains($id, '/') ? strstr($id, '/', true) : 'outros';
            $groups[$vendor][$id] = "{$name} · {$id}";
        }

        ksort($groups);

        return $this->withCurrent([
            'groups' => $groups,
            'note' => $live
                ? 'Lista do /models do OpenRouter ('.count($models).' modelos). Escolha um que aceite saída estruturada (JSON schema).'
                : 'Lista fixa: o /models do OpenRouter não respondeu. A chave fica em OPENROUTER_API_KEY no .env.',
        ], $current);
    }

    public function hermes(string $current): array
    {
        $url = (string) Settings::get('enricher.hermes_url');
        $live = $this->live('hermes:'.md5($url), fn () => (new HermesEnricher)->models());
        $aliases = array_values(array_unique([...($live ?? []), ...config('secmon.enricher.hermes.aliases')]));

        return $this->withCurrent([
            'groups' => [
                '' => ['' => 'o do hermes (padrão do hermes.conf)'],
                'Alias: a versão mais nova da família' => array_combine($aliases, $aliases),
                'Nome completo: versão fixa' => $this->labelled(config('secmon.enricher.claude.models')),
            ],
            'note' => $live
                ? 'Aliases informados pelo hermes.'
                : 'hermes fora do ar: aliases da lista fixa.',
        ], $current);
    }

    /** @param  array<string, string>  $models  id => nome */
    private function labelled(array $models): array
    {
        return collect($models)->mapWithKeys(fn ($name, $id) => [$id => "{$name} · {$id}"])->all();
    }

    private function withCurrent(array $catalog, string $current): array
    {
        $listed = collect($catalog['groups'])->contains(fn (array $options) => array_key_exists($current, $options));

        if ($current !== '' && ! $listed) {
            // Ex.: modelo aposentado ou escrito à mão antes do select existir.
            $catalog['groups'] = ['Em uso' => [$current => $current]] + $catalog['groups'];
        }

        return $catalog;
    }

    private function live(string $key, callable $fetch): ?array
    {
        $key = 'secmon:models:'.$key;
        $cached = Cache::get($key);

        if (! is_array($cached)) {
            try {
                $cached = $fetch();
            } catch (Throwable $e) {
                Log::info('Lista de modelos indisponível', ['lista' => $key, 'erro' => $e->getMessage()]);
                $cached = [];
            }

            Cache::put($key, $cached, $cached === [] ? self::FAILURE_TTL : self::TTL);
        }

        return $cached === [] ? null : $cached;
    }
}
