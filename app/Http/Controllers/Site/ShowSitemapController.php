<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\ShowSitemapRequest;
use App\Services\Sitemap;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * O /sitemap.xml e, num site grande, cada parte do índice (/sitemap-N.xml).
 * O robô que pergunta com o ETag da última leitura recebe 304, sem o corpo,
 * enquanto nenhuma matéria mudar.
 */
class ShowSitemapController extends Controller
{
    public function __construct(private Sitemap $sitemap) {}

    public function __invoke(ShowSitemapRequest $request, ?string $part = null): Response
    {
        $file = $this->sitemap->file($part === null ? null : (int) $part);

        abort_if($file === null, 404);

        $response = response($file['xml'], 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=900',
            // O mapa em si não é página para aparecer na busca.
            'X-Robots-Tag' => 'noindex',
        ]);
        $response->setEtag($file['etag']);

        if ($file['lastmod']) {
            $response->setLastModified(Carbon::parse($file['lastmod']));
        }

        $response->isNotModified($request);

        return $response;
    }
}
