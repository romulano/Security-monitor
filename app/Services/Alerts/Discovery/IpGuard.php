<?php

namespace App\Services\Alerts\Discovery;

/**
 * Instalação local, de um dono só: o Security Monitor não recusa IP por faixa (privada,
 * loopback, link-local, metadata da nuvem em 169.254.169.254, CGNAT, reservada, as formas
 * disfarçadas de IPv4 dentro de IPv6...). O dono decide os alvos — inclusive a própria rede
 * interna, se quiser varrer ou testar algo nela — então `blockReason()` só confere se a
 * string é um IP sintaticamente válido; o único motivo de bloqueio que resta é
 * "endereço inválido". Os nomes dos métodos (`isPublic`, `blockReason`) ficaram como
 * estavam, para não editar as ~13 chamadas pelo código: aqui "público" só quer dizer
 * "é um IP", não "fora de faixa privada".
 *
 * O que ainda bloqueia é outra coisa, e continua de pé: as listas EXPLÍCITAS e configuráveis
 * pelo dono (`PENTEST_FORBIDDEN_ADDRESSES`, `secmon.own_addresses`), comparadas por
 * `IpList::contains()`. `canonical()` e `embeddedIpv4()` continuam aqui porque essas listas
 * precisam comparar o IP numa forma só: o mesmo endereço escrito em IPv4 ou disfarçado
 * dentro de IPv6 (`::ffff:a.b.c.d`, o NAT64 `64:ff9b::/96`) não pode escapar da lista.
 */
final class IpGuard
{
    /** Nulo se a string é um IP válido; senão, o motivo do bloqueio (sempre "endereço inválido"). */
    public static function blockReason(string $ip): ?string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) ? null : 'endereço inválido';
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
}
