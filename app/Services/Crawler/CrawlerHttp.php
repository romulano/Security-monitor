<?php

namespace App\Services\Crawler;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * A requisição do crawler: User-Agent, timeout e, se a fonte tiver, o proxy.
 * Só a coleta das fontes (feed e página da matéria) passa por aqui; IA,
 * notificadores, Radar e GitHub continuam saindo direto pelo IP do servidor.
 */
class CrawlerHttp
{
    /**
     * `$proxy` vai direto para o cURL: http, https, socks5 ou socks5h (este
     * resolve o DNS no próprio proxy, então nem a consulta DNS sai pelo servidor).
     */
    public function request(?string $proxy = null): PendingRequest
    {
        $request = Http::withHeaders(['User-Agent' => (string) Settings::get('crawler.user_agent')])
            ->timeout(Settings::int('crawler.timeout'));

        if ($proxy) {
            $request = $request->withOptions(['proxy' => $proxy]);
        }

        return $request;
    }

    /**
     * Tira usuário e senha de qualquer URL do texto: `//usuario:senha@` vira `//***@`.
     *
     * A mensagem de erro do cURL pode trazer a URL do proxy, e ela vai para a
     * execução, para a fonte e para o log.
     */
    public static function redact(string $message): string
    {
        return (string) preg_replace('#//[^/\s@]+@#', '//***@', $message);
    }
}
