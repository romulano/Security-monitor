<?php

namespace App\Services\Security;

use App\Models\SecuritySite;
use App\Services\Alerts\Discovery\SafeFetcher;
use App\Services\Security\Checks\DnsCheck;
use App\Services\Security\Checks\DomainExpiryCheck;
use App\Services\Security\Checks\ExposureCheck;
use App\Services\Security\Checks\HttpCheck;
use App\Services\Security\Checks\SecurityTxtCheck;
use App\Services\Security\Checks\SiteCheck;
use App\Services\Security\Checks\TlsCheck;
use Throwable;

/**
 * Roda todas as verificações de um site e monta o relatório. Só lê: GET, DNS e um handshake TLS,
 * sempre pelas travas anti-SSRF (SafeFetcher e IpGuard), com teto de requisições por varredura.
 * Uma verificação que falha vira "não testável" sem derrubar as outras.
 */
class SiteScanner
{
    public function __construct(
        private TlsProbe $tls,
    ) {}

    public function scan(SecuritySite $site): ScanReport
    {
        $started = microtime(true);

        // Defesa em profundidade: o cadastro já passa pelo SiteHost, mas uma linha que entrou por outro
        // caminho (banco, seed, import) com formato inválido nunca vira requisição.
        if (SiteHost::normalize($site->host) !== $site->host) {
            return new ScanReport([], 'erro', 0, null, null, 0, 'endereço do site inválido');
        }

        $fetcher = app(SafeFetcher::class);
        $fetcher->startScan((int) config('secmon.security.request_budget', 25));
        $probe = new SiteProbe($site, $fetcher, $this->tls);

        $results = [];

        foreach ($this->checks() as $check) {
            try {
                array_push($results, ...$check->run($probe));
            } catch (Throwable $e) {
                array_push($results, ...array_map(
                    fn (string $id) => CheckResult::untested($id, 'a verificação falhou: '.mb_substr($e->getMessage(), 0, 80)),
                    $check->ids(),
                ));
            }
        }

        $home = $probe->home();
        $tls = $probe->tls();
        $reachable = $probe->answered($home) || $tls->reachable;

        return ScanReport::build(
            $results,
            $home['status'] ?? null,
            $tls,
            (int) round((microtime(true) - $started) * 1000),
            $reachable,
        );
    }

    /** @return list<SiteCheck> */
    private function checks(): array
    {
        return [
            app(TlsCheck::class),
            app(HttpCheck::class),
            app(ExposureCheck::class),
            app(SecurityTxtCheck::class),
            app(DnsCheck::class),
            app(DomainExpiryCheck::class),
        ];
    }
}
