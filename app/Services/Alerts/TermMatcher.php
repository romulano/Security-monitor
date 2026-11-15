<?php

namespace App\Services\Alerts;

use Illuminate\Support\Str;

/**
 * Como um termo do perfil casa com um texto: palavra inteira, sem caixa e sem
 * acento; com * no fim, por prefixo ("financ*" casa "financial services").
 *
 * É a regra que decide o alerta. A query do Elasticsearch (percolator) é só um
 * pré-filtro e sempre devolve um superconjunto do que esta regra aceita.
 */
final class TermMatcher
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function regex(string $term): string
    {
        if (isset(self::$cache[$term])) {
            return self::$cache[$term];
        }

        $wildcard = str_ends_with($term, '*');
        $body = preg_quote(self::fold(rtrim($term, '*')), '/');

        return self::$cache[$term] = '/(?<![\p{L}\p{N}])'.$body.($wildcard ? '' : '(?![\p{L}\p{N}])').'/u';
    }

    public static function contains(?string $text, string $term): bool
    {
        return $text !== null && self::inFolded(self::fold($text), $term);
    }

    /** @param  iterable<string>  $values */
    public static function anyValue(iterable $values, string $term): bool
    {
        foreach ($values as $value) {
            if (self::contains((string) $value, $term)) {
                return true;
            }
        }

        return false;
    }

    /** Para quem já normalizou o texto com fold() — evita refazer por termo. */
    public static function inFolded(string $folded, string $term): bool
    {
        if ($folded === '' || trim($term, ' *') === '') {
            return false;
        }

        return preg_match(self::regex($term), $folded) === 1;
    }

    /** @param  list<string>  $folded */
    public static function anyFolded(array $folded, string $term): bool
    {
        foreach ($folded as $value) {
            if (self::inFolded($value, $term)) {
                return true;
            }
        }

        return false;
    }

    /** Sem acento e sem caixa, para "Saúde" casar com "saude". */
    public static function fold(string $text): string
    {
        return mb_strtolower(Str::ascii($text));
    }
}
