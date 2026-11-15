<?php

namespace App\Services\Enrichment;

/**
 * Saída normalizada do enriquecimento, no formato de exibição da matéria:
 * título reescrito, resumo em markdown, criticidade e os grupos de
 * indicadores (`[{field, label, values[]}]`).
 */
class EnrichmentResult
{
    public function __construct(
        public string $title,
        public string $summary,
        public ?string $criticity,
        public array $indicators = [],
        public bool $isNewInfo = true,
        // Falso quando a IA recusa o agrupamento automático: o artigo é de
        // outro assunto e vira caso próprio.
        public bool $sameCase = true,
        public ?string $newContext = null,
        public string $driver = 'heuristic',
        // Edição pt-BR do mesmo relatório; nula quando o driver não a produz.
        public ?string $titlePt = null,
        public ?string $summaryPt = null,
        public ?string $newContextPt = null,
        // Relatório do caso enriquecido com este artigo (título e resumo, em
        // inglês e pt-BR). Nulo quando não houve caso existente para enriquecer.
        public ?string $caseTitle = null,
        public ?string $caseSummary = null,
        public ?string $caseTitlePt = null,
        public ?string $caseSummaryPt = null,
    ) {}

    /** Converte `{cves: [...], iocs: [...]}` nos grupos rotulados da UI. */
    public static function indicatorGroups(array $raw): array
    {
        $groups = [];

        foreach (config('secmon.indicator_fields') as $field => $label) {
            $values = $raw[$field] ?? [];

            if (! is_array($values)) {
                $values = [$values];
            }

            $values = array_values(array_unique(array_filter(
                array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : null, $values),
                fn ($v) => $v !== null && $v !== ''
            )));

            if ($values !== []) {
                $groups[] = ['field' => $field, 'label' => $label, 'values' => $values];
            }
        }

        return $groups;
    }

    public function values(string $field): array
    {
        foreach ($this->indicators as $group) {
            if (($group['field'] ?? null) === $field) {
                return $group['values'] ?? [];
            }
        }

        return [];
    }
}
