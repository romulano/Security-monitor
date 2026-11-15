<?php

namespace App\Services\Domains;

use App\Services\Alerts\Discovery\DnsResolver;

/**
 * O DNS de um subdomínio da empresa (DESENHO-5.3 §3.4): resolve? está na zona da empresa? Só DNS, pelo resolvedor do
 * Security Monitor; nada vai ao servidor do cliente. As regras são as do recon passivo da stack (PassiveRecon::resolve, ::chain,
 * ::zone e a B2 da P9c), reescritas aqui para não tocar na stack:
 *
 * - a cadeia de CNAME até o fim (até 8 saltos); basta um salto fora do domínio para ela sair da zona (`cname_fora`);
 * - sem CNAME, um NS no próprio nome é corte de zona: com algum NS fora do domínio, quem controla é outro (`delegado`);
 * - acima do nome, até 3 níveis do domínio para baixo, a zona filha com NS de fora (`zona_filha`) ou que não respondeu,
 *   ou o nome mais fundo que isso (`zona_desconhecida`); o mesmo para cada salto do CNAME dentro do domínio;
 * - resolve = tem A/AAAA no fim da cadeia. O DNS que não respondeu é "não sei" (`dns_mudo`), nunca "não existe".
 *
 * `outside` verdadeiro: fora da zona da empresa (ou sem como saber). A E3b ativa assim mesmo o que a pessoa cadastrou
 * (P5, com a marca); a descoberta da E3c só sugere.
 */
class SubdomainCheck
{
    public const MAX_HOPS = 8;

    public const ZONE_LEVELS = 3;

    public function __construct(private DnsResolver $dns) {}

    /** @return array{resolves: ?bool, outside: bool, reason: ?string, facts: array<string, mixed>} */
    public function check(string $domain, string $name): array
    {
        [$chain, $chainUnknown, $chainStop] = $this->chain($name);

        if ($chainUnknown) {
            return self::result(null, true, 'dns_mudo', ['cname' => $chain]);
        }

        $cnameOut = array_filter($chain, fn (string $hop) => ! self::inside($domain, $hop)) !== [];
        $ns = $chain === [] ? $this->dns->nsOrNull($name) : [];

        if ($ns === null) {
            return self::result(null, true, 'dns_mudo', ['cname' => $chain]);
        }

        $delegated = $ns !== [];
        $nsOut = array_filter($ns, fn (string $host) => ! self::inside($domain, rtrim(strtolower($host), '.'))) !== [];
        $zone = $this->zone($domain, $name) ?? $this->hopZone($domain, $chain);
        $ips = $chainStop === null ? $this->dns->ipsOrNull($chain === [] ? $name : end($chain)) : [];

        if ($ips === null) {
            return self::result(null, true, 'dns_mudo', ['cname' => $chain]);
        }

        $reason = match (true) {
            $cnameOut => 'cname_fora',
            $delegated && $nsOut => 'delegado',
            $zone === 'desconhecido' => 'zona_desconhecida',
            $zone !== null => 'zona_filha',
            $ips === [] => 'sem_ip',
            default => null,
        };
        $outside = $cnameOut || ($delegated && $nsOut) || $zone !== null;

        return self::result($ips !== [], $outside, $reason, [
            'cname' => $chain,
            'cname_out' => $cnameOut,
            'cname_stop' => $chainStop,
            'delegated' => $delegated,
            'ns_out' => $delegated && $nsOut,
            'zone' => $zone,
            'ips' => array_slice(array_values(array_unique($ips)), 0, 5),
        ]);
    }

    /**
     * A cadeia de CNAME: os saltos (sem o próprio nome), se o DNS deixou de responder no meio e por que ela parou antes
     * do fim (`laco` ou `longa`). @return array{0: list<string>, 1: bool, 2: ?string}
     */
    private function chain(string $name): array
    {
        $chain = [];
        $current = $name;

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $targets = $this->dns->cnameOrNull($current);

            if ($targets === null) {
                return [$chain, true, null];
            }

            $next = isset($targets[0]) ? rtrim(strtolower($targets[0]), '.') : null;

            if ($next === null) {
                return [$chain, false, null];
            }

            if ($next === $name || in_array($next, $chain, true)) {
                return [$chain, false, 'laco'];
            }

            $chain[] = $next;
            $current = $next;
        }

        return [$chain, false, 'longa'];
    }

    /** A 1ª zona filha com NS fora do domínio acima do nome; `desconhecido` se um nível não respondeu ou é fundo demais. */
    private function zone(string $domain, string $name): ?string
    {
        $labels = explode('.', substr($name, 0, -strlen($domain) - 1));

        for ($depth = 1; $depth < count($labels); $depth++) {
            if ($depth > self::ZONE_LEVELS) {
                return 'desconhecido';
            }

            $level = implode('.', array_slice($labels, -$depth)).'.'.$domain;
            $ns = $this->dns->nsOrNull($level);

            if ($ns === null) {
                return 'desconhecido';
            }

            if (array_filter($ns, fn (string $host) => ! self::inside($domain, rtrim(strtolower($host), '.'))) !== []) {
                return $level;
            }
        }

        return null;
    }

    /**
     * A zona de quem responde pelos saltos do CNAME dentro do domínio (Y3 do Red Team da E3b1): o salto que é corte de
     * zona com NS de fora, ou que fica numa zona filha de fora, é de outro, mesmo com o nome da empresa. O domínio
     * principal fica de fora (os NS dele são o provedor de DNS da própria empresa). `desconhecido` se não respondeu.
     *
     * @param  list<string>  $chain
     */
    private function hopZone(string $domain, array $chain): ?string
    {
        foreach ($chain as $hop) {
            if ($hop === $domain || ! self::inside($domain, $hop)) {
                continue;
            }

            $ns = $this->dns->nsOrNull($hop);

            if ($ns === null) {
                return 'desconhecido';
            }

            if (array_filter($ns, fn (string $host) => ! self::inside($domain, rtrim(strtolower($host), '.'))) !== []) {
                return $hop;
            }

            if (($zone = $this->zone($domain, $hop)) !== null) {
                return $zone;
            }
        }

        return null;
    }

    private static function inside(string $domain, string $host): bool
    {
        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    private static function result(?bool $resolves, bool $outside, ?string $reason, array $facts): array
    {
        return ['resolves' => $resolves, 'outside' => $outside, 'reason' => $reason, 'facts' => $facts + ['outside' => $outside]];
    }
}
