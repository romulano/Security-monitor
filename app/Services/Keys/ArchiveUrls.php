<?php

namespace App\Services\Keys;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * O recon passivo da varredura de chaves: os endereços que o Web Archive (archive.org) já viu do
 * domínio e dos subdomínios dele, pela API de índice (CDX). Acha as páginas que nenhum link público
 * aponta mais (apps em /app/…, fluxos antigos) sem mandar nada ao site: a navegação abre depois só
 * o que o escopo, o robots.txt e as ações negadas deixarem.
 *
 * Só o nome do domínio sai do Security Monitor (ele é público). Fora do ar ou lento, a varredura segue sem ele.
 */
final class ArchiveUrls
{
    public const LIMIT = 5000;

    /** @return list<string> os endereços, sem repetir, como o archive guardou */
    public function urls(string $domain): array
    {
        // Um retrato variado do site numa consulta só (a do domínio inteiro, com filtros, cai em 503 num site grande): o
        // `collapse` pelos 18 primeiros caracteres do caminho junta as milhares de páginas de cidade em uma e deixa cada app
        // (/app/casa/, /app/consumer/…) com a sua; o filtro tira os caminhos com id (4 dígitos seguidos), os perfis.
        $surt = implode(',', array_reverse(explode('.', strtolower($domain)))).')/';
        $query = implode('&', array_map(fn (array $pair) => $pair[0].'='.rawurlencode($pair[1]), [
            ['url', $domain.'/*'], ['fl', 'original'], ['collapse', 'urlkey:'.(strlen($surt) + 18)],
            ['filter', '!urlkey:.*[0-9]{4}.*'], ['limit', (string) self::LIMIT],
        ]));

        try {
            $response = Http::timeout(30)->connectTimeout(5)
                ->withHeaders(['User-Agent' => (string) config('secmon.crawler.user_agent')])
                ->get((string) config('secmon.keys.archive_url', 'https://web.archive.org/cdx/search/cdx').'?'.$query);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $urls = [];

        foreach (preg_split('/\r\n|\n/', substr($response->body(), 0, 8 * 1024 * 1024)) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && preg_match('~^https?://~i', $line) && strlen($line) <= 2000) {
                $urls[$line] = true;
            }
        }

        return array_keys($urls);
    }
}
