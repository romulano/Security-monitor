<?php

namespace App\Services\Alerts\Discovery;

/**
 * Só deixa a descoberta falar com IP público. O domínio é do cliente, mas o
 * DNS dele pode apontar (ou redirecionar) para dentro da nossa rede: 10/8,
 * metadata da nuvem em 169.254.169.254, o Postgres do compose...
 *
 * O IP público do próprio servidor do Security Monitor passa por aqui de propósito: a
 * monitoria do admin lê os sites dele que resolvem para esse IP (prova
 * "servidor" do SiteOwnership).
 */
final class IpGuard
{
    /** Faixas que o filter_var não cobre ou que precisam de nome no log. */
    private const BLOCKED = [
        '0.0.0.0/8' => 'rede "esta"',
        '10.0.0.0/8' => 'rede privada',
        '100.64.0.0/10' => 'CGNAT',
        '127.0.0.0/8' => 'loopback',
        '169.254.0.0/16' => 'link-local / metadata da nuvem',
        '172.16.0.0/12' => 'rede privada',
        '192.0.0.0/24' => 'reservado IETF',
        '192.0.2.0/24' => 'documentação',
        '192.88.99.0/24' => 'relay 6to4 (obsoleto)',
        '192.168.0.0/16' => 'rede privada',
        '198.18.0.0/15' => 'benchmark',
        '198.51.100.0/24' => 'documentação',
        '203.0.113.0/24' => 'documentação',
        '224.0.0.0/4' => 'multicast',
        '240.0.0.0/4' => 'reservado',
        '::/128' => 'não especificado',
        '::1/128' => 'loopback',
        'fc00::/7' => 'rede privada IPv6',
        'fe80::/10' => 'link-local IPv6',
        'ff00::/8' => 'multicast IPv6',
        '2001:db8::/32' => 'documentação IPv6',
        // IPv6 que leva um IPv4 dentro: 2002:a00:1:: é o 10.0.0.1 pelo 6to4, e o Teredo esconde o IPv4
        // com XOR. Nenhum site usa essas formas, e quem as aceitasse chegaria à rede interna por elas.
        '::/96' => 'IPv4-compatível (obsoleto)',
        '64:ff9b:1::/48' => 'NAT64 de uso local',
        '2001::/32' => 'Teredo (IPv4 embutido)',
        '2002::/16' => '6to4 (IPv4 embutido)',
        // Faixas de uso especial do IANA: nenhuma é endereço de site.
        '100::/64' => 'descarte IPv6',
        '2001:2::/48' => 'benchmark IPv6',
        '2001:10::/28' => 'ORCHID',
        '2001:20::/28' => 'ORCHIDv2',
        '3fff::/20' => 'documentação IPv6',
        '5f00::/16' => 'SRv6',
    ];

    /** Todo IPv6 de site fica no espaço global (2000::/3); fora dele só há faixa reservada ou especial. */
    private const GLOBAL_IPV6 = '2000::/3';

    /** Nulo se o IP é público; senão, o motivo do bloqueio. */
    public static function blockReason(string $ip): ?string
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return 'endereço inválido';
        }

        // IPv4 embutido em IPv6 (::ffff:10.0.0.1, 64:ff9b::a00:1) é julgado como IPv4.
        if ($embedded = self::embeddedIpv4($ip)) {
            return self::blockReason($embedded);
        }

        foreach (self::BLOCKED as $cidr => $reason) {
            if (self::inCidr($ip, $cidr)) {
                return $reason;
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && ! self::inCidr($ip, self::GLOBAL_IPV6)) {
            return 'fora do espaço IPv6 global';
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'faixa reservada';
        }

        return null;
    }

    public static function isPublic(string $ip): bool
    {
        return self::blockReason($ip) === null;
    }

    /**
     * O IP numa forma só: o IPv4 de dentro do IPv6 que o embute (::ffff:203.0.113.10
     * e 64:ff9b::1f61:5bd6 são o 203.0.113.10) e o IPv6 na forma curta. Quem compara
     * IP com lista (os proibidos da stack de testes) compara esta forma; senão, o
     * mesmo endereço escrito em IPv6 escaparia da lista. Inválido volta como veio.
     */
    public static function canonical(string $ip): string
    {
        if ($embedded = self::embeddedIpv4($ip)) {
            return $embedded;
        }

        $bin = filter_var($ip, FILTER_VALIDATE_IP) ? inet_pton($ip) : false;

        return $bin === false ? $ip : (string) inet_ntop($bin);
    }

    private static function embeddedIpv4(string $ip): ?string
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return null;
        }

        $bin = inet_pton($ip);
        $mapped = str_repeat("\0", 10)."\xff\xff";
        $nat64 = "\x00\x64\xff\x9b".str_repeat("\0", 8);

        if (str_starts_with($bin, $mapped) || str_starts_with($bin, $nat64)) {
            return inet_ntop(substr($bin, 12));
        }

        return null;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;

        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($subnetBin[$bytes]) & ord($mask));
    }
}
