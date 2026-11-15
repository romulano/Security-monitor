<?php

namespace App\Support;

/**
 * Manipulação da barra de busca no formato Elasticsearch query_string.
 *
 * A UI trabalha com "chips": cada clique numa faceta adiciona ou remove um
 * token `campo:valor` da consulta, preservando o texto livre que o usuário
 * digitou. Aqui ficam as funções que quebram e remontam essa string sem
 * estragar aspas.
 */
class QueryTokens
{
    /**
     * Até quantos filtros `campo:valor` uma busca leva. O robô da busca de
     * 2026-11-01 combinava 3 a 5 facetas aleatórias, cada combinação uma
     * consulta nova ao Elasticsearch; gente quase nunca passa de 3.
     */
    public const MAX_FILTERS = 4;

    /**
     * Quantos filtros `campo:valor` a busca tem, inclusive entre parênteses e
     * depois de NOT ou de `-`; o "http://" de um endereço digitado não conta.
     */
    public static function filterCount(string $query): int
    {
        return preg_match_all('~(?<![\w.:/])[a-z_][\w.]*:(?!//)~i', $query);
    }

    /** Quebra a query em tokens de topo, respeitando "aspas". */
    public static function split(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $tokens = [];
        $buffer = '';
        $inQuotes = false;

        foreach (str_split($query) as $char) {
            if ($char === '"') {
                $inQuotes = ! $inQuotes;
                $buffer .= $char;

                continue;
            }

            if ($char === ' ' && ! $inQuotes) {
                if ($buffer !== '') {
                    $tokens[] = $buffer;
                    $buffer = '';
                }

                continue;
            }

            $buffer .= $char;
        }

        if ($buffer !== '') {
            $tokens[] = $buffer;
        }

        return $tokens;
    }

    public static function join(array $tokens): string
    {
        return trim(implode(' ', array_filter($tokens, fn ($t) => trim((string) $t) !== '')));
    }

    /** Monta `campo:valor`, colocando aspas quando o valor tem espaço. */
    public static function token(string $field, string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $needsQuotes = preg_match('/[\s:()]/', $value) === 1;

        return $field.':'.($needsQuotes ? '"'.$value.'"' : $value);
    }

    public static function has(string $query, string $field, string $value): bool
    {
        $needle = mb_strtolower(self::token($field, $value));

        foreach (self::split($query) as $token) {
            if (mb_strtolower($token) === $needle) {
                return true;
            }
        }

        return false;
    }

    public static function add(string $query, string $field, string $value): string
    {
        if (self::has($query, $field, $value)) {
            return $query;
        }

        $tokens = self::split($query);
        $tokens[] = self::token($field, $value);

        return self::join($tokens);
    }

    public static function remove(string $query, string $field, string $value): string
    {
        $needle = mb_strtolower(self::token($field, $value));

        return self::join(array_filter(
            self::split($query),
            fn ($token) => mb_strtolower($token) !== $needle
        ));
    }

    public static function toggle(string $query, string $field, string $value): string
    {
        return self::has($query, $field, $value)
            ? self::remove($query, $field, $value)
            : self::add($query, $field, $value);
    }

    /** Criticidade é exclusiva: trocar substitui o token existente. */
    public static function criticity(string $query): ?string
    {
        foreach (self::split($query) as $token) {
            if (preg_match('/^criticity:"?([a-z]+)"?$/i', $token, $m)) {
                return mb_strtolower($m[1]);
            }
        }

        return null;
    }

    public static function setCriticity(string $query, string $value): string
    {
        $tokens = array_filter(self::split($query), fn ($t) => ! preg_match('/^criticity:/i', $t));

        if (self::criticity($query) !== mb_strtolower($value)) {
            $tokens[] = 'criticity:'.mb_strtolower($value);
        }

        return self::join($tokens);
    }

    /**
     * Chips exibidos abaixo da barra de busca. Operadores booleanos viram
     * rótulo estático: remover um "AND" isolado não é uma ação que faça sentido.
     */
    public static function chips(string $query): array
    {
        return array_values(array_map(fn ($token) => [
            'token' => $token,
            'is_field' => (bool) preg_match('/^[a-z_]+:/i', $token),
            'is_operator' => in_array(mb_strtoupper($token), ['AND', 'OR', 'NOT'], true),
        ], self::split($query)));
    }

    public static function removeToken(string $query, string $token): string
    {
        $needle = mb_strtolower($token);

        return self::join(array_filter(
            self::split($query),
            fn ($t) => mb_strtolower($t) !== $needle
        ));
    }
}
