<?php

namespace App\Support;

use App\Services\Alerts\Discovery\IpGuard;

/**
 * Listas de endereços que vêm da configuração (vírgula no .env): só o que é IP,
 * ou IP e faixa (CIDR), sem repetição. O resto sai calado: um erro de digitação
 * não pode virar "endereço" nem derrubar a página.
 */
final class IpList
{
    /** @return list<string> */
    public static function ips(iterable $entries): array
    {
        return self::clean($entries, ranges: false);
    }

    /** @return list<string> IPs e faixas (203.0.113.0/24, 2a02:4780::/64) */
    public static function ipsAndRanges(iterable $entries): array
    {
        return self::clean($entries, ranges: true);
    }

    /**
     * O IP está na lista (IPs e faixas, como as de ipsAndRanges())? O IP e cada IP
     * da lista são comparados na forma canônica (IpGuard::canonical): o IPv4 escrito
     * em IPv6 (::ffff:a.b.c.d, o NAT64 64:ff9b::/96) é o próprio IPv4 (C3 do Red
     * Team). Fora isso, IPv4 e IPv6 não se misturam: 10.0.0.1 nunca cai numa faixa
     * IPv6, nem o contrário.
     *
     * @param  iterable<string>  $entries
     */
    public static function contains(iterable $entries, string $ip): bool
    {
        $bin = @inet_pton(IpGuard::canonical($ip));

        if ($bin === false) {
            return false;
        }

        foreach ($entries as $entry) {
            [$net, $bits] = array_pad(explode('/', (string) $entry, 2), 2, null);
            $netBin = @inet_pton($bits === null ? IpGuard::canonical($net) : $net);

            if ($netBin === false || strlen($netBin) !== strlen($bin)) {
                continue;
            }

            $bits = $bits === null ? strlen($bin) * 8 : max(0, min((int) $bits, strlen($bin) * 8));
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;

            if (substr($bin, 0, $bytes) === substr($netBin, 0, $bytes)
                && ($rest === 0 || (ord($bin[$bytes]) >> (8 - $rest)) === (ord($netBin[$bytes]) >> (8 - $rest)))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function clean(iterable $entries, bool $ranges): array
    {
        $out = [];

        foreach ($entries as $entry) {
            $entry = trim((string) $entry);
            [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;

            if (filter_var($ip, FILTER_VALIDATE_IP) && ($bits === null || ($ranges && ctype_digit($bits) && (int) $bits <= $max))) {
                $out[$entry] = true;
            }
        }

        return array_keys($out);
    }
}
