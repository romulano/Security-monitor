<?php

namespace App\Services\Alerts;

use App\Models\AlertProfile;
use App\Models\NewsCase;
use App\Support\Settings;

/**
 * Decide se um caso é alerta para um perfil e por quê.
 *
 * Pesos: cita a empresa/marca/domínio 100 (ignora nível e tipos, se o cliente
 * pediu); produto ou fornecedor da stack 60; termo da stack só no texto 40
 * (tecnologias amplas, marcadas `strict` no catálogo, não contam no texto);
 * setor da empresa 40 (só com "notícias do meu setor"). Bônus: CVE junto com
 * a stack +20, país onde o cliente opera +10. O score fica em 0–100.
 */
class MatchEngine
{
    private const PRODUCT_FIELDS = ['affected_products', 'vendors'];

    public function __construct(private ProfileCompiler $compiler) {}

    /** @param  NewsCase|array  $case  model ou o _source do Elasticsearch */
    public function evaluate(AlertProfile $profile, NewsCase|array $case, ?array $terms = null): MatchResult
    {
        $doc = $case instanceof NewsCase ? $this->documentOf($case) : $case;
        $terms ??= $this->compiler->terms($profile);

        // Normaliza uma vez; cada termo só roda a regex.
        $fold = fn (array $values) => array_map(fn ($v) => TermMatcher::fold((string) $v), $values);
        $products = $fold(array_merge(...array_map(fn ($f) => (array) ($doc[$f] ?? []), self::PRODUCT_FIELDS)));
        $orgs = $fold((array) ($doc['victim_orgs'] ?? []));
        $sectors = $fold((array) ($doc['victim_sectors'] ?? []));
        $text = TermMatcher::fold($this->textOf($doc));
        $reasons = [];

        foreach ($terms['brand'] as $term => $label) {
            if (TermMatcher::anyFolded($orgs, $term)) {
                $reasons[] = $this->reason('brand', $label, 'victim_orgs', 100);
            } elseif (TermMatcher::inFolded($text, $term)) {
                $reasons[] = $this->reason('brand', $label, 'texto', 100);
            }
        }

        foreach ($terms['stack'] as $term => $label) {
            if (TermMatcher::anyFolded($products, $term)) {
                $reasons[] = $this->reason('stack', $label, 'affected_products', 60);
            } elseif (TermMatcher::inFolded($text, $term)) {
                $reasons[] = $this->reason('mention', $label, 'texto', 40);
            }
        }

        // Tecnologias amplas (Windows, Python, GitHub...) só contam como produto.
        foreach ($terms['strict'] ?? [] as $term => $label) {
            if (TermMatcher::anyFolded($products, $term)) {
                $reasons[] = $this->reason('stack', $label, 'affected_products', 60);
            }
        }

        foreach ($terms['sector'] as $term => $label) {
            if (TermMatcher::anyFolded($sectors, $term)) {
                $reasons[] = $this->reason('sector', $label, 'victim_sectors', 40);
            }
        }

        // Um rótulo aparece uma vez só, com o motivo mais forte.
        $reasons = collect($reasons)
            ->sortByDesc('weight')
            ->unique(fn ($r) => $r['type'] === 'mention' ? 'stack'.$r['label'] : $r['type'].$r['label'])
            ->unique('label')
            ->values()
            ->all();

        $base = (int) collect($reasons)->max('weight');
        $hasStack = collect($reasons)->contains(fn ($r) => in_array($r['type'], ['stack', 'mention'], true));

        if ($hasStack && ($cve = ((array) ($doc['cves'] ?? []))[0] ?? null)) {
            $reasons[] = $this->reason('cve', (string) $cve, 'cves', 20);
        }

        if ($base > 0 && $terms['regions'] !== [] && array_intersect(
            array_map('strtoupper', (array) ($doc['countries_code'] ?? [])),
            $terms['regions']
        ) !== []) {
            $reasons[] = $this->reason('region', 'sua região', 'countries_code', 10);
        }

        $score = min(100, $base + (int) collect($reasons)->whereIn('type', ['cve', 'region'])->sum('weight'));
        $result = new MatchResult($reasons, $score, null);

        return new MatchResult($reasons, $score, $result->matched() ? $this->suppression($profile, $doc, $result) : null);
    }

    /** Mesmos campos que vão para o Elasticsearch, para os dois lados verem o mesmo caso. */
    public function documentOf(NewsCase $case): array
    {
        $doc = [
            'title' => $case->title,
            'title_pt' => $case->title_pt,
            'summary' => $case->summary,
            'summary_pt' => $case->summary_pt,
            'criticity' => $case->criticity,
        ];

        foreach (array_keys(config('secmon.indicator_fields')) as $field) {
            $doc[$field] = $case->indicatorValues($field);
        }

        return $doc;
    }

    private function suppression(AlertProfile $profile, array $doc, MatchResult $result): ?string
    {
        // Cita a empresa: avisa sempre, em qualquer nível e de qualquer tipo.
        if ($result->isBrand()) {
            return null;
        }

        if (! in_array($doc['criticity'] ?? 'low', $profile->allowedLevels(), true)) {
            return 'below_level';
        }

        if (! $this->typeAllowed($profile, $doc)) {
            return 'type_filter';
        }

        if ($result->score < Settings::int('alerts.min_score')) {
            return 'low_score';
        }

        return null;
    }

    /**
     * Sem tipo escolhido, tudo passa. Caso sem classificação nenhuma também
     * passa: não dá para dizer que está fora do que o cliente quer.
     */
    private function typeAllowed(AlertProfile $profile, array $doc): bool
    {
        $wanted = $profile->attack_types ?? [];
        // Tipos escritos pelo cliente ("Outro"), já com os termos em inglês.
        $custom = $this->compiler->terms($profile)['attack'];

        if ($wanted === [] && $custom === []) {
            return true;
        }

        $attackTypes = (array) ($doc['attack_type'] ?? []);
        $signals = [
            'cves' => (array) ($doc['cves'] ?? []) !== [],
            'data' => (array) ($doc['data_types_exposed'] ?? []) !== [],
            'fraud' => (array) ($doc['fraud_type'] ?? []) !== [],
        ];

        if ($attackTypes === [] && ! in_array(true, $signals, true)) {
            return true;
        }

        foreach ($wanted as $value) {
            $option = $this->compiler->option('attack_types', $value) ?? [];

            if (($option['signal'] ?? null) && ($signals[$option['signal']] ?? false)) {
                return true;
            }

            foreach ($option['terms'] ?? [] as $term) {
                // attack_type vem em snake_case ("remote_code_execution").
                if (TermMatcher::anyValue(array_map(fn ($v) => str_replace('_', ' ', $v), $attackTypes), str_replace('_', ' ', $term))) {
                    return true;
                }
            }
        }

        // O que o cliente escreveu pode ser golpe ("golpe do pix") e não ataque.
        $labels = array_map(fn ($v) => str_replace('_', ' ', (string) $v), array_merge($attackTypes, (array) ($doc['fraud_type'] ?? [])));

        foreach ($custom as $term) {
            if (TermMatcher::anyValue($labels, str_replace('_', ' ', $term))) {
                return true;
            }
        }

        return false;
    }

    private function textOf(array $doc): string
    {
        $parts = [$doc['title'] ?? '', $doc['title_pt'] ?? '', $doc['summary'] ?? '', $doc['summary_pt'] ?? ''];

        foreach (array_keys(config('secmon.indicator_fields')) as $field) {
            if (! in_array($field, ['victim_orgs', 'iocs'], true)) {
                $parts[] = implode(' | ', (array) ($doc[$field] ?? []));
            }
        }

        return implode("\n", array_filter($parts));
    }

    private function reason(string $type, string $label, string $field, int $weight): array
    {
        return ['type' => $type, 'label' => $label, 'field' => $field, 'weight' => $weight];
    }
}
