<?php

namespace App\Services\Monitoring;

use App\Exceptions\ActionRefused;
use App\Support\IpList;
use App\Support\Settings;

/**
 * Admin → Acessos: os acessos "nossos" (a conferência do deploy, os scripts, as
 * conferências feitas de casa). Contam como nossos a rede interna e o loopback,
 * os endereços do próprio servidor (secmon.own_addresses, o SECMON_OWN_ADDRESSES)
 * e a lista que o admin edita na tela, com IP ou faixa (CIDR). Nada é gravado
 * sobre o acesso: a tela compara o IP que ele já tem.
 */
class OwnAddresses
{
    public const SETTING = 'monitoring.own_addresses';

    /** Loopback, rede interna (a do Docker entre elas) e link-local, IPv4 e IPv6. */
    public const INTERNAL = [
        '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', '169.254.0.0/16',
        '::1/128', 'fc00::/7', 'fe80::/10',
    ];

    /**
     * Os do servidor, que vêm da configuração.
     *
     * @return list<string>
     */
    public function server(): array
    {
        return IpList::ipsAndRanges((array) config('secmon.own_addresses', []));
    }

    /**
     * A lista da tela, como o admin deixou.
     *
     * @return list<string>
     */
    public function edited(): array
    {
        return IpList::ipsAndRanges((array) Settings::get(self::SETTING, []));
    }

    /**
     * Tudo o que conta como nosso.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return IpList::ipsAndRanges([...self::INTERNAL, ...$this->server(), ...$this->edited()]);
    }

    /**
     * O acesso é nosso, em SQL sobre a coluna `ip` (page_views, access_events): o IP, lido como inet, cai numa das
     * faixas. O pg_input_is_valid deixa de fora, sem erro, um IP que não se leia.
     *
     * @return array{sql: string, bindings: list<string>}
     */
    public function sql(): array
    {
        return [
            'sql' => "(case when pg_input_is_valid(ip, 'inet') then cast(ip as inet) <<= any(cast(? as inet[])) else false end)",
            'bindings' => ['{'.implode(',', $this->all()).'}'],
        ];
    }

    public function contains(string $ip): bool
    {
        return IpList::contains($this->all(), $ip);
    }

    /**
     * Troca a lista da tela; o que não é IP nem faixa sai (o form request já recusa).
     *
     * @param  iterable<string>  $entries
     */
    public function save(iterable $entries): int
    {
        $entries = IpList::ipsAndRanges($entries);
        Settings::set(self::SETTING, $entries);

        return count($entries);
    }

    /**
     * Põe o IP na lista da tela; devolve falso se ele já contava como nosso (o
     * IP de casa do dono muda, e o botão "Adicionar o meu IP atual" põe o de agora).
     */
    public function add(string $ip): bool
    {
        if (IpList::ips([$ip]) === []) {
            throw new ActionRefused('Não consegui ler o seu IP.');
        }

        if ($this->contains($ip)) {
            return false;
        }

        $this->save([...$this->edited(), $ip]);

        return true;
    }
}
