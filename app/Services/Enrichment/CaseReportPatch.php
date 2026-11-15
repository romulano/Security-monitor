<?php

namespace App\Services\Enrichment;

use App\Support\ReportSections;
use InvalidArgumentException;

/**
 * Atualização por trechos do relatório da matéria: em vez de devolver o
 * relatório inteiro de novo a cada artigo (em inglês e em português), a
 * análise devolve só as partes que o artigo mudou, e esta classe as encaixa
 * no relatório atual.
 *
 * Função pura: recebe o markdown e o trecho, devolve o markdown novo, ou nulo
 * quando o trecho não dá para encaixar (quem chama refaz a análise no
 * formato de relatório inteiro).
 */
final class CaseReportPatch
{
    /**
     * @param  array<string, ?string>  $patch  parte => texto novo; nulo ou ausente mantém a parte, "" a remove
     * @param  string  $lang  `en` ou `pt`, o idioma dos títulos das partes
     *
     * @throws InvalidArgumentException parte que o relatório não tem (fora de ReportSections::KEYS)
     */
    public static function apply(?string $current, array $patch, string $lang): ?string
    {
        if ($unknown = array_diff(array_keys($patch), ReportSections::KEYS)) {
            throw new InvalidArgumentException('Parte do relatório desconhecida no trecho: '.implode(', ', $unknown));
        }

        // Sem relatório não há onde encaixar; com linha do tempo, remontar
        // descartaria o que só estava nela.
        if (trim((string) $current) === '' || ReportSections::hasTimeline($current)) {
            return null;
        }

        $sections = ReportSections::split($current);

        foreach ($patch as $key => $text) {
            if ($text !== null) {
                $sections[$key] = trim($text);
            }
        }

        // Relatório sem a abertura não vai ao ar.
        if (trim((string) $sections['body']) === '') {
            return null;
        }

        return ReportSections::join($sections, $lang);
    }
}
