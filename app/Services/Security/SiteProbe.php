<?php

namespace App\Services\Security;

use App\Models\SecuritySite;
use App\Services\Alerts\Discovery\SafeFetcher;

/**
 * O que uma varredura sabe do site, buscado uma vez e dividido entre as verificações: a página
 * inicial em HTTPS, a resposta da porta 80 (sem seguir o redirecionamento) e o handshake TLS.
 *
 * Toda requisição sai pelo SafeFetcher (só portas 80 e 443, IP público, IP fixado na conexão,
 * redirecionamentos conferidos um a um, teto de requisições da varredura). O corpo das páginas
 * é lido e descartado: nenhuma verificação o guarda.
 */
class SiteProbe
{
    /** @var array<string, mixed>|null */
    private ?array $home = null;

    /** @var array<string, mixed>|null */
    private ?array $http = null;

    private ?TlsReport $tls = null;

    public function __construct(
        public readonly SecuritySite $site,
        private SafeFetcher $fetcher,
        private TlsProbe $tlsProbe,
    ) {}

    public function host(): string
    {
        return $this->site->host;
    }

    /** A página inicial em HTTPS (segue até 3 redirecionamentos, como um navegador). @return array<string, mixed> */
    public function home(): array
    {
        return $this->home ??= $this->get('https://'.$this->host().'/', bytes: (int) config('secmon.security.home_bytes'), accept: 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5');
    }

    /** A porta 80, sem seguir o redirecionamento: o primeiro 3xx é a resposta que interessa. @return array<string, mixed> */
    public function http(): array
    {
        return $this->http ??= $this->get('http://'.$this->host().'/', bytes: 2048, follow: false);
    }

    public function tls(): TlsReport
    {
        return $this->tls ??= $this->tlsProbe->inspect($this->host());
    }

    /** Um GET num caminho do site, lendo no máximo `$bytes`. @return array<string, mixed> */
    public function fetch(string $path, int $bytes, bool $follow = false, string $accept = '*/*'): array
    {
        return $this->get('https://'.$this->host().$path, bytes: $bytes, follow: $follow, accept: $accept);
    }

    /** A página inicial respondeu (com qualquer status HTTP)? */
    public function answered(array $response): bool
    {
        return $response['status'] !== null;
    }

    /** Valor de um cabeçalho (o primeiro), em minúsculas no nome. */
    public static function header(array $response, string $name): ?string
    {
        $values = $response['headers'][strtolower($name)] ?? null;

        return is_array($values) && $values !== [] ? (string) $values[0] : null;
    }

    /** @return array<string, mixed> */
    private function get(string $url, int $bytes, bool $follow = true, string $accept = '*/*'): array
    {
        return $this->fetcher->get(
            $url,
            fn () => null,
            expectMissing: true,
            followRedirects: $follow,
            accept: $accept,
            maxBytes: $bytes,
            timeout: (int) config('secmon.security.timeout', 8),
        );
    }
}
