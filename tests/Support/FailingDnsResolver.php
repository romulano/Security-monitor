<?php

namespace Tests\Support;

use App\Services\Alerts\Discovery\DnsResolver;

/**
 * O DNS de todo teste que não pede outro: não responde. As listas vêm vazias e os *OrNull,
 * nulos ("não sei"), como o NativeDnsResolver sem rede. Teste que precisa de DNS liga o
 * FakeDnsResolver, que entra por cima deste.
 */
class FailingDnsResolver implements DnsResolver
{
    /** @var list<string> o que foi perguntado, para o teste conferir que nada saiu */
    public array $asked = [];

    public function txt(string $host): array
    {
        $this->asked[] = "txt {$host}";

        return [];
    }

    public function txtOrNull(string $host): ?array
    {
        $this->asked[] = "txt {$host}";

        return null;
    }

    public function ptrOrNull(string $ip): ?array
    {
        $this->asked[] = "ptr {$ip}";

        return null;
    }

    public function mx(string $host): array
    {
        $this->asked[] = "mx {$host}";

        return [];
    }

    public function ns(string $host): array
    {
        $this->asked[] = "ns {$host}";

        return [];
    }

    public function ips(string $host): array
    {
        $this->asked[] = "ips {$host}";

        return [];
    }

    public function nsOrNull(string $host): ?array
    {
        $this->asked[] = "ns {$host}";

        return null;
    }

    public function ipsOrNull(string $host): ?array
    {
        $this->asked[] = "ips {$host}";

        return null;
    }

    public function cname(string $host): array
    {
        $this->asked[] = "cname {$host}";

        return [];
    }

    public function cnameOrNull(string $host): ?array
    {
        $this->asked[] = "cname {$host}";

        return null;
    }
}
