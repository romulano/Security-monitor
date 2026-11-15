<?php

namespace Tests\Feature\Alerts;

use App\Services\Alerts\Discovery\DnsResolver;

/**
 * DNS em memória: host => [tipo => valores], e os curingas (`*.nível`) para o que não tem registro próprio. Como o
 * DNS de verdade, o TXT de um apelido (records[host]['cname']) é o do destino; o txtOwnedOrNull() não o aproveita.
 */
class FakeDnsResolver implements DnsResolver
{
    public array $records = [];

    /** nível => [tipo => valores]: um nome sem registro próprio logo abaixo do nível responde com isto (o `*.nível`). */
    public array $wildcards = [];

    public array $asked = [];

    /** host (ou IP) => quantas consultas ainda falham (timeout): os *OrNull devolvem nulo. */
    public array $failing = [];

    public function set(string $host, string $type, array $values): static
    {
        $this->records[$host][$type] = $values;

        return $this;
    }

    public function txt(string $host): array
    {
        return $this->lookup($host, 'txt');
    }

    public function txtOrNull(string $host): ?array
    {
        return $this->failure($host) ? null : $this->lookup($host, 'txt');
    }

    /** Pelo txtOrNull(): o teste que o troca para simular o DNS também vale aqui. */
    public function txtOwnedOrNull(string $host): ?array
    {
        $records = $this->txtOrNull($host);

        return $records === null || ($this->records[$host]['cname'] ?? []) === [] ? $records : [];
    }

    /** O PTR fica em records[ip]['ptr']. */
    public function ptrOrNull(string $ip): ?array
    {
        return $this->failure($ip) ? null : $this->lookup($ip, 'ptr');
    }

    public function mx(string $host): array
    {
        return $this->lookup($host, 'mx');
    }

    public function ns(string $host): array
    {
        return $this->lookup($host, 'ns');
    }

    public function ips(string $host): array
    {
        return $this->lookup($host, 'ips');
    }

    public function nsOrNull(string $host): ?array
    {
        return $this->failure($host) ? null : $this->lookup($host, 'ns');
    }

    public function ipsOrNull(string $host): ?array
    {
        return $this->failure($host) ? null : $this->lookup($host, 'ips');
    }

    public function cname(string $host): array
    {
        return $this->lookup($host, 'cname');
    }

    public function cnameOrNull(string $host): ?array
    {
        return $this->failure($host) ? null : $this->lookup($host, 'cname');
    }

    /** Um curinga no nível: `*.{$level}` responde `$type` com `$values`. */
    public function wildcard(string $level, string $type, array $values): static
    {
        $this->wildcards[$level][$type] = $values;

        return $this;
    }

    private function failure(string $host): bool
    {
        if (($this->failing[$host] ?? 0) <= 0) {
            return false;
        }

        $this->failing[$host]--;
        $this->asked[] = "falhou {$host}";

        return true;
    }

    private function lookup(string $host, string $type): array
    {
        $this->asked[] = "{$type} {$host}";

        for ($hops = 0; $type === 'txt' && $hops < 8 && ($this->records[$host]['cname'] ?? []) !== []; $hops++) {
            $host = $this->records[$host]['cname'][0];
        }

        if (isset($this->records[$host])) {
            return $this->records[$host][$type] ?? [];
        }

        // Sem registro próprio, o curinga do nível de cima responde (só para o nome, não para o IP do PTR).
        $parent = substr($host, strpos($host, '.') + 1);

        return str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP) ? ($this->wildcards[$parent][$type] ?? []) : [];
    }
}
