<?php

namespace App\Services\Enrichment;

use App\Models\Article;
use App\Models\NewsCase;
use App\Support\Prompts;
use App\Support\ReportSections;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Prompt e JSON Schema compartilhados pelos drivers Claude, OpenAI e hermes,
 * para que todos produzam exatamente a mesma estrutura.
 */
class EnrichmentPrompt
{
    /** Relatório do caso mostrado à IA: inteiro, porque é a base que ela enriquece. */
    private const REPORT_CHARS = 20000;

    /**
     * Tempo da análise com trechos que ainda permite refazê-la no formato de
     * relatório inteiro: abaixo do limite do job (300 s), com a folga da
     * requisição ao hermes.
     */
    private const PATCH_BUDGET = 255;

    /** Menos que isso sobrando, a análise não é refeita: a matéria não chegaria a ser gravada. */
    private const PATCH_MIN_REDO = 60;

    /**
     * Regras da análise; o texto é editável no admin (Prompts › Análise de
     * cada artigo). Na atualização por trechos ganha as regras do `case_patch`,
     * fixas no código: valem mesmo com o texto editado no admin.
     */
    public static function system(bool $patch = false): string
    {
        $rules = Prompts::text('enrichment.system');

        return $patch ? rtrim($rules)."\n\n".self::patchRules() : $rules;
    }

    /**
     * Se esta atualização pede só as partes do relatório que mudam: chave
     * ligada, matéria com o relatório nos dois idiomas, sem linha do tempo
     * (remontar a descartaria) e inteiro no prompt (a parte cortada não
     * poderia ser reescrita).
     */
    public static function patchMode(?NewsCase $existing): bool
    {
        if (! config('secmon.enricher.case_patch') || ! $existing) {
            return false;
        }

        foreach ([$existing->summary, $existing->summary_pt] as $report) {
            if (trim((string) $report) === '' || mb_strlen((string) $report) > self::REPORT_CHARS || ReportSections::hasTimeline($report)) {
                return false;
            }
        }

        return trim((string) $existing->title_pt) !== '';
    }

    /**
     * Roda a análise com trechos quando dá (patchMode) e, se a resposta não
     * encaixa no relatório, refaz no formato de relatório inteiro, enquanto
     * houver tempo para isso dentro do job.
     *
     * @param  callable(bool $patch, ?int $seconds): EnrichmentResult  $analyze  `$seconds`: o tempo que sobra para a refação
     */
    public static function withPatch(Article $article, ?NewsCase $existing, callable $analyze): EnrichmentResult
    {
        if (! self::patchMode($existing)) {
            return $analyze(false, null);
        }

        $started = microtime(true);

        try {
            return $analyze(true, null);
        } catch (CasePatchFailed $e) {
            $left = self::PATCH_BUDGET - (int) ceil(microtime(true) - $started);
            Log::warning('Análise: o trecho do relatório não encaixou; refeita com o relatório inteiro', [
                'artigo' => $article->id, 'caso' => $existing->id, 'erro' => $e->getMessage(), 'segundos_sobrando' => $left,
            ]);

            if ($left < self::PATCH_MIN_REDO) {
                throw $e;
            }

            // Quantas vezes o trecho não encaixou, por dia: mede se o formato está valendo a pena.
            Cache::increment('enrichment:patch_fallback:'.now()->format('Y-m-d'));

            return $analyze(false, $left);
        }
    }

    public static function user(Article $article, ?NewsCase $existing = null, bool $patch = false): string
    {
        $content = Str::limit((string) $article->content, (int) config('secmon.crawler.max_content_chars'), '');

        $parts = [
            '<article>',
            'source_host: '.$article->source_host,
            'url: '.$article->url,
            'published_at: '.optional($article->published_at)->toIso8601String(),
            'author: '.($article->author ?: 'unknown'),
            'original_title: '.$article->original_title,
            '',
            $content !== '' ? $content : (string) $article->summary,
            '</article>',
        ];

        if ($existing) {
            $known = collect($existing->indicators ?? [])
                ->map(fn ($g) => $g['field'].': '.implode(', ', $g['values']))
                ->implode("\n");

            $parts[] = '';
            $parts[] = '<existing_case>';
            $parts[] = Prompts::text('enrichment.existing_case');
            $parts[] = '';
            $parts[] = 'known_title: '.$existing->title;
            $parts[] = 'known_report:';
            $parts[] = Str::limit((string) $existing->summary, self::REPORT_CHARS, '');
            if ($patch) {
                $parts[] = 'known_report_pt:';
                $parts[] = Str::limit((string) $existing->summary_pt, self::REPORT_CHARS, '');
            }

            $parts[] = 'known_indicators:';
            $parts[] = $known;
            $parts[] = '</existing_case>';
        }

        $parts[] = '';
        $parts[] = 'Return only the structured record.';

        return implode("\n", $parts);
    }

    /**
     * As regras do `case_patch`, fixas no código. Partes e títulos são os de
     * ReportSections, que é quem remonta o relatório.
     */
    public static function patchRules(): string
    {
        return <<<'TXT'
        Case report by sections (this request has known_report_pt):
        - Instead of `case_summary` and `case_summary_pt`, return `case_patch` with only the
          sections of the case report that change with this article. `known_report` (English)
          and `known_report_pt` (Brazilian Portuguese) are the current report. Its sections are
          `body` (the opening, before the first heading), `technical` (### Technical Details /
          ### Detalhes Técnicos), `fix` (### Fix Details / ### Detalhes da correção),
          `remediation` (### Remediation / ### Remediação) and `conflicting`
          (### Conflicting Reports / ### Relatos divergentes).
        - For each section that changes, write the whole new section in `en` and in `pt`,
          without its heading. It replaces the old section entirely: keep every fact, source
          attribution and detail of the old one that is still right, and never return only
          the new sentences. Any other heading inside a section (e.g. ### Affected Versions in
          the opening) is part of that section: keep it when you rewrite the section.
        - A section this article changes nothing in is null. An empty string removes a
          section (only when all of it moved to another section or was proven wrong).
        - Every rule for updating an existing case still applies, section by section, and
          `case_title` and `case_title_pt` are still the full titles.
        - `case_patch` is null exactly when the four case fields would be null.
        TXT;
    }

    /** JSON Schema aceito tanto pelo `output_config.format` quanto pelo `response_format` da OpenAI. */
    public static function schema(bool $patch = false): array
    {
        $stringArray = fn (string $description) => [
            'type' => 'array',
            'description' => $description,
            'items' => ['type' => 'string'],
        ];

        $properties = [
            'title' => ['type' => 'string', 'description' => 'Incident-focused headline, max 140 chars.'],
            'summary' => ['type' => 'string', 'description' => 'Markdown analytical summary.'],
            'criticity' => [
                'type' => 'string',
                'enum' => config('secmon.criticities'),
                'description' => 'Operational urgency for a defender.',
            ],
            'same_case' => ['type' => 'boolean', 'description' => 'False when the article covers a different incident than existing_case. True when there is no existing_case.'],
            'is_new_info' => ['type' => 'boolean', 'description' => 'False only when this restates an existing case.'],
            'new_context' => ['type' => ['string', 'null'], 'description' => 'One paragraph with what is new, or null.'],
            'title_pt' => ['type' => 'string', 'description' => 'The title in Brazilian Portuguese.'],
            'summary_pt' => ['type' => 'string', 'description' => 'The Markdown summary in Brazilian Portuguese.'],
            'new_context_pt' => ['type' => ['string', 'null'], 'description' => 'new_context in Brazilian Portuguese, or null.'],
            'case_title' => ['type' => ['string', 'null'], 'description' => 'Title of the existing case after this update, or null.'],
            'case_summary' => ['type' => ['string', 'null'], 'description' => 'known_report enriched with this article (Markdown), or null.'],
            'case_title_pt' => ['type' => ['string', 'null'], 'description' => 'case_title in Brazilian Portuguese, or null.'],
            'case_summary_pt' => ['type' => ['string', 'null'], 'description' => 'case_summary in Brazilian Portuguese, or null.'],
            // A regra vai também aqui, fora do texto editável: vale mesmo com o prompt editado no admin.
            'cves' => $stringArray('Only the CVEs the article is about (affecting the product or incident it covers), e.g. CVE-2026-16812. Not CVEs of other vendors mentioned in passing (KEV lists, same-week news) nor older CVEs recalled as background.'),
            'related_cves' => $stringArray('CVEs the article names only as context: other vendors in passing, older CVEs as background. Never one already in cves.'),
            'iocs' => $stringArray('IPs, domains, URLs, hashes and filenames.'),
            'malware_families' => $stringArray('Malware and tool families.'),
            'ttps_attack' => $stringArray('MITRE ATT&CK technique IDs.'),
            'threat_actors' => $stringArray('Threat actor / ransomware group names.'),
            'victim_sectors' => $stringArray('Lowercase sectors, e.g. healthcare, government.'),
            'affected_products' => $stringArray('Affected products and versions.'),
            'vendors' => $stringArray('Vendors and companies involved.'),
            'attack_type' => $stringArray('Lowercase snake_case, e.g. ransomware, supply_chain.'),
            'initial_access_vector' => $stringArray('Lowercase snake_case, e.g. phishing, exploit_public_facing.'),
            'countries' => $stringArray('Country names.'),
            'countries_code' => $stringArray('ISO 3166-1 alpha-2 codes.'),
            'loss_type' => $stringArray('e.g. data, financial, operational.'),
            'fraud_type' => $stringArray('e.g. phishing_scam, bec, card_fraud.'),
            'data_types_exposed' => $stringArray('e.g. credentials, personal_data, financial_data.'),
            'researchers' => $stringArray('Researchers or research teams credited.'),
            'authors' => $stringArray('Article authors.'),
            'source_confidence' => $stringArray('Single element describing the source type.'),
        ];

        if ($patch) {
            $properties = self::withCasePatch($properties);
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * Troca `case_summary` e `case_summary_pt` por `case_patch`, no mesmo
     * lugar (a ordem dos campos é a ordem em que o modelo escreve).
     */
    private static function withCasePatch(array $properties): array
    {
        $section = [
            'type' => ['object', 'null'],
            'properties' => [
                'en' => ['type' => 'string', 'description' => 'The whole section in English, without its heading.'],
                'pt' => ['type' => 'string', 'description' => 'The same section in Brazilian Portuguese, without its heading.'],
            ],
            'required' => ['en', 'pt'],
            'additionalProperties' => false,
        ];

        $patch = [
            'type' => ['object', 'null'],
            'description' => 'Only the sections of known_report that change with this article, each rewritten in full in English and in Brazilian Portuguese; null for the sections that stay the same. Null when the four case fields would be null.',
            'properties' => array_fill_keys(ReportSections::KEYS, $section),
            'required' => ReportSections::KEYS,
            'additionalProperties' => false,
        ];

        $out = [];

        foreach ($properties as $name => $property) {
            if ($name === 'case_summary') {
                $out['case_patch'] = $patch;
            } elseif ($name !== 'case_summary_pt') {
                $out[$name] = $property;
            }
        }

        return $out;
    }
}
