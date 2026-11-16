<?php

namespace Tests\Unit;

use App\Services\Alerts\Discovery\IpGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Instalação local, de um dono só (15/11): o IpGuard não bloqueia mais por faixa (rede
 * privada, loopback, link-local, metadata da nuvem, CGNAT, reservada, as formas
 * disfarçadas de IPv4 dentro de IPv6...) — só confere se a string é um IP. O que ainda
 * bloqueia (as listas explícitas, `PENTEST_FORBIDDEN_ADDRESSES`/`secmon.own_addresses`)
 * é de `IpList`, não daqui.
 */
class IpGuardTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function onceBlockedNowAccepted(): array
    {
        return [
            'início de 10/8' => ['10.0.0.0'],
            'fim de 10/8' => ['10.255.255.255'],
            'início de 172.16/12' => ['172.16.0.0'],
            'fim de 172.16/12' => ['172.31.255.255'],
            '192.168/16' => ['192.168.0.1'],
            'loopback no meio' => ['127.200.3.4'],
            'metadata da nuvem' => ['169.254.169.254'],
            'início do CGNAT' => ['100.64.0.0'],
            'fim do CGNAT' => ['100.127.255.255'],
            '0/8' => ['0.1.2.3'],
            'início do benchmark' => ['198.18.0.0'],
            'fim do benchmark' => ['198.19.255.255'],
            'documentação' => ['203.0.113.9'],
            'multicast' => ['224.0.0.1'],
            'reservado' => ['240.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'loopback v6' => ['::1'],
            'não especificado v6' => ['::'],
            'ULA fc00' => ['fc00::1'],
            'ULA fdff' => ['fdff:ffff::1'],
            'link-local v6' => ['fe80::1234'],
            'multicast v6' => ['ff02::1'],
            'documentação v6' => ['2001:db8::1'],
            'IPv4 mapeado em v6 (loopback)' => ['::ffff:127.0.0.1'],
            'IPv4 mapeado em v6 (metadata)' => ['::ffff:169.254.169.254'],
            'NAT64 de IP privado' => ['64:ff9b::c0a8:1'],
            // IPv6 que leva um IPv4 dentro.
            '6to4 com 10.0.0.1' => ['2002:a00:1::1'],
            '6to4 com a metadata' => ['2002:a9fe:a9fe::1'],
            'Teredo' => ['2001:0:4136:e378:8000:63bf:f5ff:fffe'],
            'NAT64 de uso local com 10.0.0.1' => ['64:ff9b:1::a00:1'],
            'IPv4-compatível com 10.0.0.1' => ['::a00:1'],
            'IPv4-compatível com 127.0.0.1' => ['::7f00:1'],
            'relay 6to4' => ['192.88.99.1'],
            'descarte v6' => ['100::1'],
            'benchmark v6' => ['2001:2::1'],
            'ORCHID' => ['2001:10::1'],
            'fim do ORCHIDv2' => ['2001:2f::1'],
            'documentação v6 nova' => ['3fff::1'],
            'SRv6' => ['5f00::1'],
            'IPv4 traduzido do SIIT' => ['::ffff:0:a00:1'],
            'v6 fora de 2000::/3' => ['4000::1'],
        ];
    }

    /** O dono decide o alvo: faixa privada, loopback, metadata e afins não são mais recusados por si só. */
    #[DataProvider('onceBlockedNowAccepted')]
    public function test_once_blocked_ranges_are_now_accepted(string $ip): void
    {
        $this->assertTrue(IpGuard::isPublic($ip), $ip);
        $this->assertNull(IpGuard::blockReason($ip));
    }

    /** @return array<string, array{0: string}> */
    public static function validAddresses(): array
    {
        return [
            'IPv4' => ['1.1.1.1'],
            'IPv6' => ['2606:4700:4700::1111'],
            'IPv4 mapeado em v6' => ['::ffff:8.8.8.8'],
            'IP do servidor do Security Monitor' => ['45.33.12.10'],
        ];
    }

    #[DataProvider('validAddresses')]
    public function test_lets_any_valid_ip_through(string $ip): void
    {
        $this->assertTrue(IpGuard::isPublic($ip), $ip);
        $this->assertNull(IpGuard::blockReason($ip));
    }

    /** @return array<string, array{0: string}> */
    public static function disguised(): array
    {
        return [
            'inteiro decimal' => ['2130706433'],
            'hexadecimal' => ['0x7f000001'],
            'octal' => ['017700000001'],
            'forma curta' => ['127.1'],
            'com zeros à esquerda' => ['010.0.0.1'],
            'com porta' => ['127.0.0.1:80'],
            'nome de host' => ['localhost'],
            'vazio' => [''],
            'com espaço' => [' 127.0.0.1'],
        ];
    }

    /**
     * Estas formas não são o que o `filter_var(..., FILTER_VALIDATE_IP)` aceita como IP,
     * então continuam recusadas — não por faixa, só porque não são um IP escrito do jeito
     * canônico. Quem aceita essas formas (host de cadastro) é o `SiteHost`, não o IpGuard.
     */
    #[DataProvider('disguised')]
    public function test_refuses_what_is_not_a_plain_ip_literal(string $value): void
    {
        $this->assertSame('endereço inválido', IpGuard::blockReason($value), $value);
        $this->assertFalse(IpGuard::isPublic($value));
    }
}
