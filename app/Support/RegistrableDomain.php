<?php

namespace App\Support;

/**
 * "mx2.zoho.com" → "zoho.com"; "_spf.rdstation.com.br" → "rdstation.com.br".
 *
 * Aproximação sem a Public Suffix List inteira: cobre os sufixos de dois
 * níveis mais comuns (o .br em especial). Basta para agrupar provedores.
 */
final class RegistrableDomain
{
    private const TWO_LEVEL_SUFFIXES = [
        'com.br', 'net.br', 'org.br', 'gov.br', 'edu.br', 'art.br', 'blog.br', 'eco.br', 'ind.br', 'inf.br', 'srv.br', 'tv.br', 'app.br', 'dev.br', 'log.br',
        'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk',
        'com.au', 'net.au', 'org.au', 'co.nz', 'co.jp', 'ne.jp', 'or.jp', 'co.kr', 'co.in', 'co.za',
        'com.mx', 'com.ar', 'com.co', 'com.pe', 'com.uy', 'com.py', 'com.bo', 'com.ec', 'com.ve', 'com.cl',
        'com.cn', 'com.hk', 'com.tw', 'com.sg', 'com.tr', 'com.pt', 'com.es', 'com.pl', 'co.il', 'co.id',
    ];

    public static function of(?string $host): ?string
    {
        $host = strtolower(trim((string) $host, " .\t\n"));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) || ! str_contains($host, '.')) {
            return null;
        }

        $labels = explode('.', $host);
        $lastTwo = implode('.', array_slice($labels, -2));
        $take = in_array($lastTwo, self::TWO_LEVEL_SUFFIXES, true) ? 3 : 2;

        return count($labels) >= $take ? implode('.', array_slice($labels, -$take)) : null;
    }

    /** O rótulo que nomeia o domínio: "rdstation" em "rdstation.com.br". */
    public static function label(string $registrable): string
    {
        return explode('.', $registrable)[0];
    }
}
