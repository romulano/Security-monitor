<?php

namespace App\Services\Alerts\Discovery;

use App\Models\TechCatalog;
use Illuminate\Support\Collection;

/**
 * Aplica as regras `detect` do catálogo ao que foi observado no domínio.
 * Confiança: cabeçalho, cookie, MX e meta generator 90 (alta); NS, CNAME,
 * TXT de verificação e script de terceiro 80; SPF e HTML 70 (média).
 */
class StackDetector
{
    public const TYPES = ['header', 'cookie', 'html', 'mx', 'spf', 'ns', 'txt', 'cname', 'generator', 'script'];

    private const CONFIDENCE = [
        'header' => 90, 'cookie' => 90, 'mx' => 90, 'generator' => 90,
        'ns' => 80, 'cname' => 80, 'txt' => 80, 'script' => 80,
        'spf' => 70, 'html' => 70,
    ];

    /**
     * @param  array{headers: array<string, list<string>>, cookies: list<string>, html: string, mx: list<string>, spf: list<string>, ns: list<string>}  $seen
     * @param  Collection<int, TechCatalog>|null  $catalog
     * @return list<array{tech: TechCatalog, label: string, version: ?string, evidence: string, confidence: int, category: string}>
     */
    public function detect(array $seen, ?Collection $catalog = null): array
    {
        $catalog ??= TechCatalog::query()->active()->get()->filter(fn (TechCatalog $t) => $t->detect !== []);
        $found = [];

        foreach ($catalog as $tech) {
            foreach ($tech->detect ?? [] as $i => $rule) {
                $hit = $this->match($rule, $seen, $tech->slug.'#'.$i);

                if (! $hit) {
                    continue;
                }

                [$evidence, $version] = $hit;
                $confidence = self::CONFIDENCE[$rule['type']] ?? 50;
                $previous = $found[$tech->id] ?? null;

                if (! $previous || $previous['confidence'] < $confidence || (! $previous['version'] && $version)) {
                    // Evidência mais forte sem versão não apaga a versão que outra regra achou.
                    $version ??= $previous['version'] ?? null;
                    $found[$tech->id] = [
                        'tech' => $tech,
                        'label' => $tech->name.($version ? ' '.$this->shortVersion($version) : ''),
                        'version' => $version,
                        'evidence' => $evidence,
                        'confidence' => max($confidence, $found[$tech->id]['confidence'] ?? 0),
                        'category' => $tech->categoryLabel(),
                    ];
                }
            }
        }

        return array_values($found);
    }

    /**
     * O que roda sobre outra tecnologia a revela: Laravel é PHP, Ubuntu é
     * Linux, Elementor é WordPress (e daí PHP). O pai entra com a evidência
     * do filho e um pouco menos de confiança. Fica fora de detect() porque o
     * cadastro automático precisa saber qual regra casou de fato.
     */
    public function withParents(array $detected): array
    {
        $found = collect($detected)->keyBy(fn (array $item) => $item['tech']->id);
        $queue = $found->all();

        while ($item = array_shift($queue)) {
            $parent = $item['tech']->parent_id ? TechCatalog::query()->active()->find($item['tech']->parent_id) : null;

            if (! $parent || $found->has($parent->id)) {
                continue;
            }

            $implied = [
                'tech' => $parent,
                'label' => $parent->name,
                'version' => null,
                'evidence' => 'deduzido de '.$item['label'].' ('.$item['evidence'].')',
                'confidence' => max(60, $item['confidence'] - 10),
                'category' => $parent->categoryLabel(),
            ];
            $found->put($parent->id, $implied);
            $queue[] = $implied;
        }

        return $found->values()->all();
    }

    /** Recomendações que saem de graça do que foi visto (versão exposta nos cabeçalhos). */
    public function hints(array $seen): array
    {
        $exposed = [];

        foreach (['server', 'x-powered-by', 'x-aspnet-version'] as $header) {
            foreach ($seen['headers'][$header] ?? [] as $value) {
                if (preg_match('/\d+\.\d+/', $value)) {
                    $exposed[] = ucwords($header, '-').': '.$value;
                }
            }
        }

        if ($exposed === []) {
            return [];
        }

        return ['o servidor expõe a versão exata do software nos cabeçalhos ('.implode('; ', $exposed).'). '
            .'Ocultar (server_tokens off no Nginx, ServerTokens Prod no Apache, expose_php = Off no PHP) dificulta o reconhecimento por quem procura alvos.'];
    }

    /**
     * `$id` é o da regra no pedido de mapeamento do teste ativo (`slug#posição`, P10): lá, a
     * regra `html` já foi casada no executor (`html_hits`), sobre a página que nunca sai de lá.
     *
     * @return array{0: string, 1: ?string}|null evidência e versão
     */
    private function match(array $rule, array $seen, string $id = ''): ?array
    {
        $pattern = (string) ($rule['pattern'] ?? '');

        if (($rule['type'] ?? null) === 'html' && array_key_exists('html_hits', $seen)) {
            $hit = $seen['html_hits'][$id] ?? null;

            return $hit === null ? null : ['HTML contém '.$hit['evidence'], $hit['version'] ?? null];
        }

        $candidates = match ($rule['type']) {
            'header' => array_map(fn ($v) => [ucwords((string) $rule['name'], '-').': '.$v, $v], $seen['headers'][strtolower((string) ($rule['name'] ?? ''))] ?? []),
            'cookie' => array_map(fn ($c) => ['cookie '.$c, $c], $seen['cookies'] ?? []),
            'mx' => array_map(fn ($h) => ['MX '.$h, $h], $seen['mx'] ?? []),
            'ns' => array_map(fn ($h) => ['NS '.$h, $h], $seen['ns'] ?? []),
            'spf' => array_map(fn ($s) => ['SPF '.$this->spfInclude($s, $pattern), $s], $seen['spf'] ?? []),
            'txt' => array_map(fn ($t) => ['TXT '.mb_strimwidth($t, 0, 90, '…'), $t], $seen['txt'] ?? []),
            'cname' => array_map(fn ($c) => ['CNAME '.$c, $c], $seen['cname'] ?? []),
            'generator' => array_map(fn ($g) => ['meta generator "'.$g.'"', $g], $seen['generators'] ?? []),
            'script' => array_map(fn ($h) => ['script de '.$h, $h], $seen['scripts'] ?? []),
            'html' => [['HTML contém '.$this->htmlSnippet($pattern), $seen['html'] ?? '']],
            default => [],
        };

        foreach ($candidates as [$evidence, $subject]) {
            if (str_starts_with($pattern, 're:')) {
                $regex = '/'.str_replace('/', '\/', substr($pattern, 3)).'/i';

                if (@preg_match($regex, $subject, $m) === 1) {
                    $evidence = $rule['type'] === 'html' ? 'HTML contém '.mb_substr($m[0], 0, 80) : $evidence;

                    return [$evidence, isset($m[1]) && $m[1] !== '' ? $m[1] : null];
                }
            } elseif ($pattern !== '' && stripos($subject, $pattern) !== false) {
                return [$evidence, null];
            }
        }

        return null;
    }

    private function spfInclude(string $spf, string $pattern): string
    {
        foreach (preg_split('/\s+/', $spf) as $part) {
            if (stripos($part, $pattern) !== false) {
                return $part;
            }
        }

        return $pattern;
    }

    private function htmlSnippet(string $pattern): string
    {
        return str_starts_with($pattern, 're:') ? substr($pattern, 3) : $pattern;
    }

    /** "1.24.0" → "1.24"; "8.2.12" → "8.2". */
    private function shortVersion(string $version): string
    {
        return preg_match('/^(\d+)(?:\.(\d+))?/', $version, $m) ? $m[1].(isset($m[2]) ? '.'.$m[2] : '') : $version;
    }
}
