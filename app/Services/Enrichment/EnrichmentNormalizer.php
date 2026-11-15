<?php

namespace App\Services\Enrichment;

use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Exploits\ExploitIndexSync;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Converte o JSON devolvido por qualquer driver de IA no EnrichmentResult.
 */
class EnrichmentNormalizer
{
    /**
     * @param  ?NewsCase  $existing  a matéria que o artigo atualiza: é nela que o `case_patch` encaixa
     *
     * @throws CasePatchFailed resposta com `case_patch` que não encaixa no relatório da matéria
     */
    public static function fromJson(string $raw, Article $article, string $driver, ?NewsCase $existing = null): EnrichmentResult
    {
        $data = self::checkCves(self::decode($raw), $article);

        $criticity = mb_strtolower(trim((string) ($data['criticity'] ?? '')));

        if (! in_array($criticity, config('secmon.criticities'), true)) {
            $criticity = null;
        }

        $title = trim((string) ($data['title'] ?? ''));
        $summary = trim((string) ($data['summary'] ?? ''));

        if ($title === '') {
            $title = (string) ($article->original_title ?: 'Sem título');
        }

        if ($summary === '') {
            $summary = (string) ($article->summary ?: Str::limit((string) $article->content, 600));
        }

        $newContext = self::text($data['new_context'] ?? null);
        $titlePt = self::text($data['title_pt'] ?? null);
        [$caseTitle, $caseSummary, $caseTitlePt, $caseSummaryPt] = array_key_exists('case_patch', $data) && ! array_key_exists('case_summary', $data)
            ? self::casePatch($data, $existing)
            : self::caseReport($data);

        return new EnrichmentResult(
            title: Str::limit($title, 240, ''),
            summary: $summary,
            criticity: $criticity,
            indicators: EnrichmentResult::indicatorGroups($data),
            isNewInfo: (bool) ($data['is_new_info'] ?? true),
            // Na dúvida (campo ausente ou valor estranho), mantém o agrupamento.
            sameCase: filter_var($data['same_case'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
            newContext: $newContext,
            driver: $driver,
            titlePt: $titlePt !== null ? Str::limit($titlePt, 240, '') : null,
            summaryPt: self::text($data['summary_pt'] ?? null),
            // Sem contexto novo em inglês não há o que traduzir.
            newContextPt: $newContext !== null ? self::text($data['new_context_pt'] ?? null) : null,
            caseTitle: $caseTitle,
            caseSummary: $caseSummary,
            caseTitlePt: $caseTitlePt,
            caseSummaryPt: $caseSummaryPt,
        );
    }

    /**
     * O relatório do caso só vale com título e texto; a edição pt-BR pela
     * metade cai inteira, para a matéria não misturar os dois idiomas.
     *
     * @return array{?string, ?string, ?string, ?string}
     */
    private static function caseReport(array $data): array
    {
        $title = self::text($data['case_title'] ?? null);
        $summary = self::text($data['case_summary'] ?? null);

        if ($title === null || $summary === null) {
            return [null, null, null, null];
        }

        $titlePt = self::text($data['case_title_pt'] ?? null);
        $summaryPt = self::text($data['case_summary_pt'] ?? null);

        if ($titlePt === null || $summaryPt === null) {
            [$titlePt, $summaryPt] = [null, null];
        }

        return [
            Str::limit($title, 240, ''),
            $summary,
            $titlePt !== null ? Str::limit($titlePt, 240, '') : null,
            $summaryPt,
        ];
    }

    /**
     * O relatório da matéria por trechos: as partes que a análise mudou,
     * encaixadas no relatório atual (inglês e português), viram o relatório
     * inteiro, como no formato antigo. Daí para frente (revisor, CaseWriter)
     * nada muda.
     *
     * @return array{?string, ?string, ?string, ?string}
     *
     * @throws CasePatchFailed
     */
    private static function casePatch(array $data, ?NewsCase $existing): array
    {
        $patch = $data['case_patch'];

        if ($patch === null) {
            return [null, null, null, null];
        }

        if (! is_array($patch) || ! $existing) {
            throw new CasePatchFailed('Trecho do relatório inválido: '.(! $existing ? 'não há matéria para encaixar.' : 'case_patch não é um objeto.'));
        }

        $en = $pt = [];

        foreach ($patch as $key => $section) {
            if ($section === null) {
                continue;
            }

            if (! is_array($section) || ! is_string($section['en'] ?? null) || ! is_string($section['pt'] ?? null)) {
                throw new CasePatchFailed("Trecho do relatório inválido: a parte {$key} não tem os textos en e pt.");
            }

            // Uma parte só em um idioma deixaria as duas edições da matéria diferentes.
            if ((trim($section['en']) === '') !== (trim($section['pt']) === '')) {
                throw new CasePatchFailed("Trecho do relatório inválido: a parte {$key} veio vazia em um idioma só.");
            }

            [$en[$key], $pt[$key]] = [$section['en'], $section['pt']];
        }

        try {
            $summary = CaseReportPatch::apply($existing->summary, $en, 'en');
            $summaryPt = CaseReportPatch::apply($existing->summary_pt, $pt, 'pt');
        } catch (InvalidArgumentException $e) {
            throw new CasePatchFailed('Trecho do relatório inválido: '.$e->getMessage(), previous: $e);
        }

        if ($summary === null || $summaryPt === null) {
            throw new CasePatchFailed('Trecho do relatório inválido: não encaixa no relatório da matéria (sem relatório, com linha do tempo ou sem a abertura).');
        }

        // O título continua inteiro na resposta; sem ele, fica o da matéria.
        $title = self::text($data['case_title'] ?? null) ?? self::text($existing->title);
        $titlePt = self::text($data['case_title_pt'] ?? null) ?? self::text($existing->title_pt);

        if ($title === null || $titlePt === null) {
            throw new CasePatchFailed('Trecho do relatório inválido: a matéria ficaria sem título.');
        }

        return [Str::limit($title, 240, ''), $summary, Str::limit($titlePt, 240, ''), $summaryPt];
    }

    /**
     * Rede de proteção das listas de CVE, sem IA nem internet: só fica o CVE
     * que está escrito no artigo (o inventado, ou o copiado do caso
     * existente, sai), no formato CVE-AAAA-NNNN. O que é assunto (`cves`)
     * não se repete no contexto (`related_cves`). Se o CVE é mesmo do
     * fornecedor da matéria não dá para conferir aqui: não há catálogo local
     * de CVE por fornecedor, e isso fica com o prompt.
     */
    private static function checkCves(array $data, Article $article): array
    {
        $written = self::cveIds(implode("\n", [$article->original_title, $article->content, $article->summary]));
        $pick = fn (string $field) => array_values(array_intersect(
            self::cveIds(implode("\n", array_filter((array) ($data[$field] ?? []), 'is_scalar'))),
            $written,
        ));

        $cves = $pick('cves');

        return ['cves' => $cves, 'related_cves' => array_values(array_diff($pick('related_cves'), $cves))] + $data;
    }

    /** @return list<string> os IDs de CVE do texto, em maiúsculas; aceita os traços tipográficos. */
    private static function cveIds(string $text): array
    {
        return ExploitIndexSync::cves(str_replace(['‐', '‑', '‒', '–', '—', '−'], '-', $text));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function decode(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            throw new RuntimeException('Resposta vazia do modelo.');
        }

        // Alguns modelos embrulham o JSON em cerca de código.
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $raw, $m)) {
            $raw = $m[1];
        } elseif (! str_starts_with($raw, '{')) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');

            if ($start === false || $end === false || $end <= $start) {
                throw new RuntimeException('Resposta do modelo não contém JSON.');
            }

            $raw = substr($raw, $start, $end - $start + 1);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // Quebra de linha ou tab crus dentro de uma string (02/11, artigo 2419
            // no claude): o JSON é recusado inteiro por um caractere. Escapados, ele lê.
            if ($e->getCode() !== JSON_ERROR_CTRL_CHAR) {
                throw new RuntimeException('JSON inválido devolvido pelo modelo: '.$e->getMessage());
            }

            try {
                $data = json_decode(self::escapeControlChars($raw), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $again) {
                throw new RuntimeException('JSON inválido devolvido pelo modelo: '.$again->getMessage());
            }
        }

        if (! is_array($data)) {
            throw new RuntimeException('JSON do modelo não é um objeto.');
        }

        return $data;
    }

    /**
     * Escapa os caracteres de controle crus (abaixo de 0x20) que estão dentro das
     * strings do JSON: \n, \t, \r, \b, \f e os demais em \u00XX. Fora das strings
     * nada muda (lá eles são espaço em branco válido ou o erro é outro).
     */
    public static function escapeControlChars(string $json): string
    {
        $named = ["\n" => '\\n', "\t" => '\\t', "\r" => '\\r', "\x08" => '\\b', "\x0C" => '\\f'];
        $out = '';
        $inString = false;
        $escaped = false;

        for ($i = 0, $n = strlen($json); $i < $n; $i++) {
            $char = $json[$i];

            if (! $inString) {
                $inString = $char === '"';
                $out .= $char;

                continue;
            }

            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === '"') {
                $inString = false;
            } elseif (ord($char) < 0x20) {
                $char = $named[$char] ?? sprintf('\\u%04x', ord($char));
            }

            $out .= $char;
        }

        return $out;
    }
}
