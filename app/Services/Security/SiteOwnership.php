<?php

namespace App\Services\Security;

use App\Models\SecuritySite;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;

/**
 * O site é de quem o cadastrou? O monitor só faz as verificações que tocam em caminhos do site
 * (arquivos expostos) quando a posse está comprovada; sem ela, só as passivas (certificado, DNS,
 * cabeçalhos da página inicial). Duas provas:
 *
 *  - servidor: o host resolve só para endereços do próprio servidor do Security Monitor (secmon.security.own_ips).
 *    Fica só com o monitor (não é TXT);
 *  - TXT: a posse da conta (DomainOwnership), conferida ao vivo em cada varredura: secmon-verify=<token
 *    da conta> no host ou num domínio de cima, até o principal (o mesmo registro vale para todos os
 *    subdomínios). É a prova dos sites atrás da Cloudflare, que não resolvem para o servidor. Só vale o
 *    TXT do próprio nome: o que chega por um CNAME é do destino. Achar o TXT aqui comprova o nome para
 *    os outros serviços da conta também.
 *
 * O declarar "sou o responsável" no cadastro não basta: sessão roubada ou CSRF cadastrariam o site
 * de um terceiro, e o IP do Security Monitor varreria os caminhos dele.
 */
class SiteOwnership
{
    public const SERVER = 'server';

    public const TXT = 'txt';

    public function __construct(private DnsResolver $dns, private DomainOwnership $ownership) {}

    /** @return ?string server | txt | nulo (sem posse comprovada) */
    public function check(SecuritySite $site): ?string
    {
        $own = array_filter((array) config('secmon.security.own_ips', []));
        $ips = $this->dns->ips($site->host);

        if ($own !== [] && $ips !== [] && array_diff($ips, $own) === []) {
            return self::SERVER;
        }

        // Ao vivo, sempre: o monitor não vive da prova guardada. O token do site é o que a tela dele mostrou.
        [$proof] = $this->ownership->check(DomainOwner::account($site->user_id), $site->host, 'monitor', [$site->verify_token]);

        return $proof ? self::TXT : null;
    }

    /** Confere agora e grava o resultado e a hora da conferência. */
    public function refresh(SecuritySite $site): ?string
    {
        $ownership = $this->check($site);
        $site->forceFill(['ownership' => $ownership, 'ownership_checked_at' => now()])->save();

        return $ownership;
    }
}
