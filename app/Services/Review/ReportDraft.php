<?php

namespace App\Services\Review;

use App\Models\NewsCase;
use App\Services\Enrichment\EnrichmentResult;

/**
 * O rascunho que a análise escreveu, campo a campo, com os nomes do JSON da
 * análise (title, summary_pt, case_summary, cves…): é o que o revisor lê, o que
 * ele cita nos problemas e o que a análise refaz.
 *
 * Dois modos: `new` (o artigo abre a matéria, ou a IA o separou da matéria
 * agrupada) e `update` (ele enriquece o relatório de uma matéria publicada).
 * Repetição (`is_new_info` falso) não muda nada no ar e não é revisada.
 */
final class ReportDraft
{
    /** Campo do JSON => propriedade do EnrichmentResult. */
    private const TEXT = [
        'title' => 'title',
        'title_pt' => 'titlePt',
        'summary' => 'summary',
        'summary_pt' => 'summaryPt',
        'new_context' => 'newContext',
        'new_context_pt' => 'newContextPt',
        'case_title' => 'caseTitle',
        'case_title_pt' => 'caseTitlePt',
        'case_summary' => 'caseSummary',
        'case_summary_pt' => 'caseSummaryPt',
        'criticity' => 'criticity',
    ];

    /** O que vai ao ar em cada modo, além da criticidade e dos indicadores. */
    private const SHOWN = [
        'new' => ['title', 'title_pt', 'summary', 'summary_pt'],
        'update' => ['case_title', 'case_title_pt', 'case_summary', 'case_summary_pt', 'new_context', 'new_context_pt'],
    ];

    /** O modo da revisão, ou nulo quando nada do que está no ar muda (repetição). */
    public static function mode(?NewsCase $existing, EnrichmentResult $result): ?string
    {
        if (! $existing || ! $result->sameCase) {
            return 'new';
        }

        return $result->isNewInfo ? 'update' : null;
    }

    /**
     * Os campos do rascunho que o revisor lê: os textos do modo, a criticidade e
     * cada grupo de indicadores (os que a análise criar depois, como um
     * `related_cves`, entram sozinhos).
     *
     * @return array<string, string|list<string>|null>
     */
    public static function fields(EnrichmentResult $result, string $mode): array
    {
        $fields = [];

        foreach ([...self::SHOWN[$mode], 'criticity'] as $field) {
            $fields[$field] = $result->{self::TEXT[$field]};
        }

        foreach (array_keys((array) config('secmon.indicator_fields')) as $field) {
            $fields[$field] = $result->values($field);
        }

        return $fields;
    }

    /** Os campos que o revisor pode citar num problema. @return list<string> */
    public static function names(string $mode): array
    {
        return [...self::SHOWN[$mode], 'criticity', ...array_keys((array) config('secmon.indicator_fields'))];
    }

    /**
     * Obrigatórios que faltam: o título, o texto e a criticidade, nos dois
     * idiomas. Na atualização, o relatório da matéria enriquecido com o artigo.
     *
     * @return list<string>
     */
    public static function missing(EnrichmentResult $result, string $mode): array
    {
        $required = $mode === 'new'
            ? ['title', 'title_pt', 'summary', 'summary_pt', 'criticity']
            : ['case_title', 'case_title_pt', 'case_summary', 'case_summary_pt', 'criticity'];

        return array_values(array_filter($required, fn (string $field) => trim((string) $result->{self::TEXT[$field]}) === ''));
    }

    /**
     * O rascunho refeito em `parte`: só os campos pedidos vêm da refação, o
     * resto continua igual ao anterior, mesmo que a IA o tenha mexido. Campo que
     * a refação deixou vazio fica como estava.
     *
     * @param  list<string>  $fields
     */
    public static function apply(EnrichmentResult $draft, EnrichmentResult $redo, array $fields): EnrichmentResult
    {
        $result = clone $draft;
        $indicators = [];

        foreach (array_keys((array) config('secmon.indicator_fields')) as $field) {
            $indicators[$field] = in_array($field, $fields, true) ? $redo->values($field) : $draft->values($field);
        }

        foreach ($fields as $field) {
            $property = self::TEXT[$field] ?? null;

            if ($property && filled($redo->{$property})) {
                $result->{$property} = $redo->{$property};
            }
        }

        $result->indicators = EnrichmentResult::indicatorGroups($indicators);

        return $result;
    }

    /**
     * O rascunho refeito em `tudo`: vale a refação inteira, mas o agrupamento é
     * o da primeira análise (o revisor confere o texto, não em que matéria o
     * artigo entra), e o texto que a refação não trouxe fica como estava.
     */
    public static function replace(EnrichmentResult $draft, EnrichmentResult $redo): EnrichmentResult
    {
        $result = clone $redo;
        $result->sameCase = $draft->sameCase;
        $result->isNewInfo = $draft->isNewInfo;
        $result->driver = $draft->driver;

        foreach (self::TEXT as $property) {
            if (blank($result->{$property})) {
                $result->{$property} = $draft->{$property};
            }
        }

        return $result;
    }
}
