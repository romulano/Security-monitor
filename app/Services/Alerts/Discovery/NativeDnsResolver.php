<?php

namespace App\Services\Alerts\Discovery;

/** Resolver do sistema (dns_get_record). Falha de DNS vira lista vazia; os *OrNull distinguem a falha. */
class NativeDnsResolver implements DnsResolver
{
    public function txt(string $host): array
    {
        return $this->txtOrNull($host) ?? [];
    }

    public function txtOrNull(string $host): ?array
    {
        $records = $this->answer($host, DNS_TXT);

        return $records === null ? null : self::texts($records);
    }

    /** @return list<string> */
    private static function texts(array $records): array
    {
        return array_values(array_filter(array_map(
            fn ($r) => isset($r['entries']) ? implode('', $r['entries']) : ($r['txt'] ?? null),
            $records,
        )));
    }

    public function ptrOrNull(string $ip): ?array
    {
        $name = self::reverseName($ip);

        if ($name === null) {
            return [];
        }

        $records = $this->answer($name, DNS_PTR);

        return $records === null ? null : array_values(array_filter(array_map(fn ($r) => isset($r['target']) ? strtolower(rtrim($r['target'], '.')) : null, $records)));
    }

    /** O nome que se pergunta no DNS reverso: 203.0.113.10 → 10.113.0.203.in-addr.arpa; IPv6, os 32 dígitos ao contrário em ip6.arpa. */
    public static function reverseName(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode('.', array_reverse(str_split(bin2hex((string) inet_pton($ip))))).'.ip6.arpa';
        }

        return null;
    }

    public function mx(string $host): array
    {
        $records = $this->records($host, DNS_MX);
        usort($records, fn ($a, $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));

        return array_values(array_filter(array_map(fn ($r) => isset($r['target']) ? strtolower(rtrim($r['target'], '.')) : null, $records)));
    }

    public function ns(string $host): array
    {
        return $this->nsOrNull($host) ?? [];
    }

    public function nsOrNull(string $host): ?array
    {
        $records = $this->answer($host, DNS_NS);

        return $records === null ? null : array_values(array_filter(array_map(fn ($r) => isset($r['target']) ? strtolower(rtrim($r['target'], '.')) : null, $records)));
    }

    public function ips(string $host): array
    {
        return $this->ipsOrNull($host) ?? [];
    }

    public function ipsOrNull(string $host): ?array
    {
        $a = $this->answer($host, DNS_A);
        $aaaa = $this->answer($host, DNS_AAAA);
        $ips = [];

        foreach ($a ?? [] as $r) {
            $ips[] = $r['ip'] ?? null;
        }

        foreach ($aaaa ?? [] as $r) {
            $ips[] = $r['ipv6'] ?? null;
        }

        $ips = array_values(array_unique(array_filter($ips)));

        return $ips === [] && ($a === null || $aaaa === null) ? null : $ips;
    }

    public function cname(string $host): array
    {
        return $this->cnameOrNull($host) ?? [];
    }

    public function cnameOrNull(string $host): ?array
    {
        $records = $this->answer($host, DNS_CNAME);

        return $records === null ? null : array_values(array_filter(array_map(fn ($r) => isset($r['target']) ? strtolower(rtrim($r['target'], '.')) : null, $records)));
    }

    private function records(string $host, int $type): array
    {
        return $this->answer($host, $type) ?? [];
    }

    /** Nulo quando o DNS falhou (o PHP devolve false); lista vazia quando o nome não existe. Protegido para os testes darem a resposta. */
    protected function answer(string $host, int $type): ?array
    {
        $records = @dns_get_record($host, $type);

        return is_array($records) ? $records : null;
    }
}
