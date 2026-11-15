<?php

namespace App\Services\Security;

/**
 * O que cada verificação prova, quanto pesa na nota e o que o dono do site faz quando ela falha.
 * Nota = 100 menos os pontos perdidos: o "crítico" perde o peso inteiro e o "aviso" metade.
 */
final class CheckCatalog
{
    /** @var array<string, string> */
    public const GROUPS = [
        'tls' => 'Certificado e TLS',
        'http' => 'HTTPS e cabeçalhos',
        'cookies' => 'Cookies e conteúdo',
        'files' => 'Arquivos expostos',
        'dns' => 'Domínio e e-mail',
    ];

    /** @var array<string, array{label: string, weight: int, group: string, advice: string}> */
    private const CHECKS = [
        'tls.valid' => ['label' => 'Certificado confiável', 'weight' => 25, 'group' => 'tls',
            'advice' => 'O navegador recusa o certificado (cadeia incompleta, nome errado ou emissor desconhecido) ou o site não serve HTTPS. Reemita o certificado e instale a cadeia completa.'],
        'tls.expiry' => ['label' => 'Validade do certificado', 'weight' => 25, 'group' => 'tls',
            'advice' => 'O certificado vence em breve (ou já venceu). Renove agora e confira a renovação automática.'],
        'tls.protocol' => ['label' => 'Versão do TLS', 'weight' => 15, 'group' => 'tls',
            'advice' => 'O servidor negocia TLS antigo. Aceite só TLS 1.2 e 1.3.'],
        'http.redirect' => ['label' => 'HTTP vai para HTTPS', 'weight' => 15, 'group' => 'http',
            'advice' => 'A porta 80 responde sem redirecionar. Redirecione tudo para https:// (301 ou 308).'],
        'http.hsts' => ['label' => 'HSTS', 'weight' => 8, 'group' => 'http',
            'advice' => 'Sem Strict-Transport-Security (ou com max-age curto) o navegador aceita voltar para HTTP. Use max-age de pelo menos 6 meses.'],
        'hdr.csp' => ['label' => 'Content-Security-Policy', 'weight' => 8, 'group' => 'http',
            'advice' => 'Sem política de conteúdo, um XSS executa qualquer script. Comece por Content-Security-Policy-Report-Only e endureça com os relatórios.'],
        'hdr.frame' => ['label' => 'Proteção contra iframe', 'weight' => 3, 'group' => 'http',
            'advice' => 'Defina X-Frame-Options ou frame-ancestors na CSP para impedir clickjacking.'],
        'hdr.nosniff' => ['label' => 'X-Content-Type-Options', 'weight' => 3, 'group' => 'http',
            'advice' => 'Envie X-Content-Type-Options: nosniff.'],
        'hdr.referrer' => ['label' => 'Referrer-Policy', 'weight' => 3, 'group' => 'http',
            'advice' => 'Defina Referrer-Policy (por exemplo, strict-origin-when-cross-origin) para não vazar endereços internos.'],
        'hdr.permissions' => ['label' => 'Permissions-Policy', 'weight' => 3, 'group' => 'http',
            'advice' => 'Desligue o que o site não usa (câmera, microfone, localização) com Permissions-Policy.'],
        'hdr.coop' => ['label' => 'Isolamento entre origens (COOP)', 'weight' => 3, 'group' => 'http',
            'advice' => 'Cross-Origin-Opener-Policy: same-origin isola a janela do site de outras origens.'],
        'leak.server' => ['label' => 'Versão no cabeçalho Server', 'weight' => 3, 'group' => 'http',
            'advice' => 'O cabeçalho Server anuncia a versão do software. Esconda (server_tokens off no nginx, ServerTokens Prod no Apache).'],
        'leak.powered' => ['label' => 'X-Powered-By', 'weight' => 3, 'group' => 'http',
            'advice' => 'X-Powered-By anuncia a linguagem e a versão. Remova (expose_php=Off no PHP).'],
        'cookie.flags' => ['label' => 'Cookies com Secure, HttpOnly e SameSite', 'weight' => 8, 'group' => 'cookies',
            'advice' => 'Cookie sem Secure trafega em HTTP; sem HttpOnly o JavaScript lê o cookie de sessão; sem SameSite fica aberto a CSRF.'],
        'mixed.content' => ['label' => 'Conteúdo misto', 'weight' => 8, 'group' => 'cookies',
            'advice' => 'A página HTTPS carrega recursos por http://. Troque por https:// (ou por caminho relativo).'],
        'file.exposed' => ['label' => 'Arquivos sensíveis', 'weight' => 25, 'group' => 'files',
            'advice' => 'Um arquivo que nunca deveria ser público está acessível. Remova do servidor, bloqueie o caminho e troque as credenciais que estavam nele.'],
        'wk.security_txt' => ['label' => 'security.txt', 'weight' => 3, 'group' => 'dns',
            'advice' => 'Publique /.well-known/security.txt com Contact: e Expires: para quem achar uma falha saber a quem avisar.'],
        'dns.spf' => ['label' => 'SPF', 'weight' => 8, 'group' => 'dns',
            'advice' => 'Sem um único registro SPF terminando em -all (ou ~all), qualquer um envia e-mail em nome do domínio.'],
        'dns.dmarc' => ['label' => 'DMARC', 'weight' => 8, 'group' => 'dns',
            'advice' => 'Publique _dmarc com p=quarantine ou p=reject; p=none só observa.'],
        'dom.expiry' => ['label' => 'Validade do domínio', 'weight' => 25, 'group' => 'dns',
            'advice' => 'O registro do domínio vence em breve. Renove e ligue a renovação automática.'],
    ];

    /** @return array<string, array{label: string, weight: int, group: string, advice: string}> */
    public static function all(): array
    {
        return self::CHECKS;
    }

    public static function has(string $id): bool
    {
        return isset(self::CHECKS[$id]);
    }

    public static function label(string $id): string
    {
        return self::CHECKS[$id]['label'] ?? $id;
    }

    public static function advice(string $id): string
    {
        return self::CHECKS[$id]['advice'] ?? '';
    }

    public static function group(string $id): string
    {
        return self::CHECKS[$id]['group'] ?? 'http';
    }

    /** Pontos que a verificação tira da nota: o peso inteiro no crítico, metade no aviso. */
    public static function penalty(string $id, string $status): int
    {
        $weight = self::CHECKS[$id]['weight'] ?? 0;

        return match ($status) {
            CheckResult::CRITICAL => $weight,
            CheckResult::WARNING => (int) ceil($weight / 2),
            default => 0,
        };
    }
}
