<?php

namespace App\Services\Leaks;

/**
 * Os sufixos públicos que o parser e a lista de webmail precisam reconhecer.
 *
 * Serve a duas perguntas: "este campo sem esquema é um endereço?" (`netflix.com:user:pass`) e
 * "o que vem depois da marca é sufixo público?" (`hotmail.co.uk` sim, `live.minhaempresa.com` não).
 *
 * Não é a Public Suffix List inteira de propósito: ela tem ~9 mil entradas e muda toda semana. Aqui
 * basta o suficiente para não confundir `john.doe` com um domínio, e quem não for reconhecido cai no
 * caminho seguro — a linha vai para um humano, não é adivinhada.
 */
final class Tlds
{
    /** @var array<string,true> */
    private static array $index = [];

    private const KNOWN = [
        'com', 'net', 'org', 'edu', 'gov', 'mil', 'int', 'info', 'biz', 'name', 'pro', 'xyz', 'top',
        'site', 'online', 'store', 'shop', 'app', 'dev', 'io', 'ai', 'co', 'me', 'tv', 'cc', 'live',
        'ac', 'ne', 'or', 'gob', 'gouv', 'asso', 'nom', 'firm', 'ind', 'web',
        'br', 'pt', 'us', 'uk', 'ca', 'mx', 'ar', 'cl', 'pe', 've', 'uy', 'py', 'bo', 'ec', 'cr',
        'de', 'fr', 'es', 'it', 'nl', 'be', 'ch', 'at', 'se', 'no', 'fi', 'dk', 'ie', 'is', 'lu',
        'pl', 'cz', 'sk', 'hu', 'ro', 'bg', 'gr', 'hr', 'si', 'rs', 'ua', 'by', 'lt', 'lv', 'ee',
        'ru', 'tr', 'il', 'sa', 'ae', 'qa', 'kw', 'bh', 'om', 'jo', 'lb', 'eg', 'ma', 'dz', 'tn',
        'za', 'ng', 'ke', 'gh', 'tz', 'ug', 'et', 'sn', 'ci',
        'in', 'pk', 'bd', 'lk', 'np', 'cn', 'jp', 'kr', 'tw', 'hk', 'mo', 'sg', 'my', 'id', 'th',
        'vn', 'ph', 'kh', 'la', 'mm', 'au', 'nz', 'fj', 'pg',
    ];

    public static function isSuffix(string $label): bool
    {
        if (self::$index === []) {
            self::$index = array_fill_keys(self::KNOWN, true);
        }

        return isset(self::$index[mb_strtolower($label)]);
    }
}
