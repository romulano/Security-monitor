<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Divide o relatório da IA (markdown) nas partes que a matéria mostra em
 * ordem própria: o conteúdo (a abertura), detalhes técnicos, detalhes da
 * correção (o PR ou commit que corrigiu, achado na pesquisa da análise),
 * remediação e relatos divergentes. A linha do tempo do relatório sai: a matéria já mostra
 * a das fontes, uma por uma.
 */
class ReportSections
{
    /** Títulos que a IA usa em cada idioma, sem acento e em minúsculas. */
    private const HEADINGS = [
        'technical' => ['technical details', 'detalhes tecnicos'],
        'fix' => ['fix details', 'detalhes da correcao'],
        'remediation' => ['remediation', 'remediacao'],
        'conflicting' => ['conflicting reports', 'relatos divergentes'],
        'timeline' => ['timeline', 'linha do tempo'],
    ];

    /** As partes que a análise pode trocar uma a uma (a atualização por trechos), na ordem da matéria. */
    public const KEYS = ['body', 'technical', 'fix', 'remediation', 'conflicting'];

    /** O título de cada parte no relatório, por idioma: os mesmos que o prompt da análise manda usar. */
    private const TITLES = [
        'en' => [
            'technical' => 'Technical Details',
            'fix' => 'Fix Details',
            'remediation' => 'Remediation',
            'conflicting' => 'Conflicting Reports',
        ],
        'pt' => [
            'technical' => 'Detalhes Técnicos',
            'fix' => 'Detalhes da correção',
            'remediation' => 'Remediação',
            'conflicting' => 'Relatos divergentes',
        ],
    ];

    /** @return array{body: string, technical: ?string, fix: ?string, remediation: ?string, conflicting: ?string} */
    public static function split(?string $markdown): array
    {
        $lines = ['body' => [], 'technical' => [], 'fix' => [], 'remediation' => [], 'conflicting' => [], 'timeline' => []];
        $current = 'body';
        $code = false;

        foreach (preg_split('/\R/u', (string) $markdown) as $line) {
            // "###" dentro de bloco de código não é título.
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $code = ! $code;
            }

            if (! $code && preg_match('/^#{2,4}\s+(.+?)\s*#*\s*$/u', $line, $m)) {
                $key = self::keyOf($m[1]);

                if ($key !== null) {
                    $current = $key;

                    continue;
                }

                // Título que a matéria não conhece fica onde está, com o título.
                // Depois da linha do tempo, volta para o conteúdo, para não sumir.
                if ($current === 'timeline') {
                    $current = 'body';
                }
            }

            $lines[$current][] = $line;
        }

        $text = fn (array $part) => ($part = trim(implode("\n", $part))) === '' ? null : $part;

        return [
            'body' => $text($lines['body']) ?? '',
            'technical' => $text($lines['technical']),
            'fix' => $text($lines['fix']),
            'remediation' => $text($lines['remediation']),
            'conflicting' => $text($lines['conflicting']),
        ];
    }

    /**
     * O caminho de volta do split(): o relatório em markdown com as partes na
     * ordem da matéria, cada uma sob o seu título no idioma (`en` ou `pt`). Parte
     * nula ou vazia fica de fora.
     *
     * @param  array<string, ?string>  $sections
     */
    public static function join(array $sections, string $lang): string
    {
        $titles = self::TITLES[$lang] ?? throw new InvalidArgumentException("Idioma do relatório desconhecido: {$lang}");
        $blocks = [];

        foreach (self::KEYS as $key) {
            $text = trim((string) ($sections[$key] ?? ''));

            if ($text !== '') {
                $blocks[] = $key === 'body' ? $text : '### '.$titles[$key]."\n".$text;
            }
        }

        return implode("\n\n", $blocks);
    }

    /** Se o relatório tem linha do tempo: o split() a descarta, então quem remonta o relatório perderia o que só estava nela. */
    public static function hasTimeline(?string $markdown): bool
    {
        $code = false;

        foreach (preg_split('/\R/u', (string) $markdown) as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $code = ! $code;
            }

            if (! $code && preg_match('/^#{2,4}\s+(.+?)\s*#*\s*$/u', $line, $m) && self::keyOf($m[1]) === 'timeline') {
                return true;
            }
        }

        return false;
    }

    private static function keyOf(string $heading): ?string
    {
        $heading = mb_strtolower(trim(str_replace(['*', '_', ':'], '', $heading)));
        $heading = strtr($heading, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c']);

        foreach (self::HEADINGS as $key => $names) {
            if (in_array($heading, $names, true)) {
                return $key;
            }
        }

        return null;
    }
}
