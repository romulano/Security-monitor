<?php

namespace App\Services\IndexNow;

use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * O POST do aviso ao IndexNow (api.indexnow.org repassa a Bing, Yandex,
 * Seznam, Naver e Yep). É aviso de endereço novo aos buscadores, não fonte de
 * notícia. O destino é fixo, da configuração, e o corpo só leva endereços do
 * próprio site: o SafeFetcher (que protege o GET da URL que vem de fora) não
 * cabe aqui.
 */
class IndexNowClient
{
    /**
     * @param  list<string>  $urls
     * @return int o status HTTP da resposta
     *
     * @throws ConnectionException sem resposta (DNS, conexão, tempo esgotado)
     */
    public function submit(string $host, string $key, string $keyLocation, array $urls): int
    {
        $body = json_encode(['host' => $host, 'key' => $key, 'keyLocation' => $keyLocation, 'urlList' => $urls], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return Http::withUserAgent((string) Settings::get('crawler.user_agent'))
            ->connectTimeout(5)
            ->timeout((int) config('secmon.indexnow.timeout'))
            ->withBody($body, 'application/json; charset=utf-8')
            ->post((string) config('secmon.indexnow.endpoint'))
            ->status();
    }
}
