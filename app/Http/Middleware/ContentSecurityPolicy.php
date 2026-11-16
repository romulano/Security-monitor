<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contra XSS: o navegador só executa o JavaScript do próprio site (o build do
 * Vite, que leva o nonce da requisição), o do captcha da Cloudflare e o script
 * inline que tiver o nonce. Um <script> ou um onclick que escape de um texto de
 * terceiros (matérias, fontes, nomes de domínio) não roda.
 *
 * O CSS aceita style="" (as views usam em larguras e cores calculadas); as
 * imagens, qualquer https (favicons e prints dos domínios do Radar). O site só
 * entra em <iframe> nele mesmo, e um formulário só sai para o próprio site.
 */
class ContentSecurityPolicy
{
    private const CAPTCHA = 'https://challenges.cloudflare.com';

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        // `npm run dev`: os scripts e o CSS vêm do servidor do Vite, que recarrega por WebSocket.
        $dev = Vite::isRunningHot() ? rtrim((string) file_get_contents(Vite::hotFile())) : '';
        $devSocket = $dev !== '' ? preg_replace('/^http/', 'ws', $dev) : '';

        return implode('; ', array_map(fn (array $directive) => implode(' ', array_filter($directive)), [
            ['default-src', "'self'"],
            ['script-src', "'self'", "'nonce-{$nonce}'", self::CAPTCHA, $dev],
            ['style-src', "'self'", "'unsafe-inline'", $dev],
            ['img-src', "'self'", 'data:', 'blob:', 'https:'],
            ['font-src', "'self'", 'data:'],
            ['connect-src', "'self'", self::CAPTCHA, $dev, $devSocket],
            ['frame-src', self::CAPTCHA],
            ['frame-ancestors', "'self'"],
            ['form-action', "'self'"],
            ['base-uri', "'self'"],
            ['object-src', "'none'"],
        ]));
    }
}
