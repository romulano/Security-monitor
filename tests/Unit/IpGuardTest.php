<?php

namespace Tests\Unit;

use App\Services\Alerts\Discovery\IpGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A barreira anti-SSRF do projeto (descoberta, Radar e a monitoria de sites):
 * só IP público passa. Os casos de borda das faixas e as formas disfarçadas de
 * 127.0.0.1 são o que costuma escapar.
 */
class IpGuardTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function blocked(): array
    {
        return [
            'início de 10/8' => ['10.0.0.0', 'rede privada'],
            'fim de 10/8' => ['10.255.255.255', 'rede privada'],
            'início de 172.16/12' => ['172.16.0.0', 'rede privada'],
            'fim de 172.16/12' => ['172.31.255.255', 'rede privada'],
            '192.168/16' => ['192.168.0.1', 'rede privada'],
            'loopback no meio' => ['127.200.3.4', 'loopback'],
            'metadata da nuvem' => ['169.254.169.254', 'link-local / metadata da nuvem'],
            'início do CGNAT' => ['100.64.0.0', 'CGNAT'],
            'fim do CGNAT' => ['100.127.255.255', 'CGNAT'],
            '0/8' => ['0.1.2.3', 'rede "esta"'],
            'início do benchmark' => ['198.18.0.0', 'benchmark'],
            'fim do benchmark' => ['198.19.255.255', 'benchmark'],
            'documentação' => ['203.0.113.9', 'documentação'],
            'multicast' => ['224.0.0.1', 'multicast'],
            'reservado' => ['240.0.0.1', 'reservado'],
            'broadcast' => ['255.255.255.255', 'reservado'],
            'loopback v6' => ['::1', 'loopback'],
            'não especificado v6' => ['::', 'não especificado'],
            'ULA fc00' => ['fc00::1', 'rede privada IPv6'],
            'ULA fdff' => ['fdff:ffff::1', 'rede privada IPv6'],
            'link-local v6' => ['fe80::1234', 'link-local IPv6'],
            'multicast v6' => ['ff02::1', 'multicast IPv6'],
            'documentação v6' => ['2001:db8::1', 'documentação IPv6'],
            'IPv4 mapeado em v6 (loopback)' => ['::ffff:127.0.0.1', 'loopback'],
            'IPv4 mapeado em v6 (metadata)' => ['::ffff:169.254.169.254', 'link-local / metadata da nuvem'],
            'NAT64 de IP privado' => ['64:ff9b::c0a8:1', 'rede privada'],
            // IPv6 que leva um IPv4 dentro: todos passavam como público.
            '6to4 com 10.0.0.1' => ['2002:a00:1::1', '6to4 (IPv4 embutido)'],
            '6to4 com a metadata' => ['2002:a9fe:a9fe::1', '6to4 (IPv4 embutido)'],
            'Teredo' => ['2001:0:4136:e378:8000:63bf:f5ff:fffe', 'Teredo (IPv4 embutido)'],
            'NAT64 de uso local com 10.0.0.1' => ['64:ff9b:1::a00:1', 'NAT64 de uso local'],
            'IPv4-compatível com 10.0.0.1' => ['::a00:1', 'IPv4-compatível (obsoleto)'],
            'IPv4-compatível com 127.0.0.1' => ['::7f00:1', 'IPv4-compatível (obsoleto)'],
            'relay 6to4' => ['192.88.99.1', 'relay 6to4 (obsoleto)'],
            'descarte v6' => ['100::1', 'descarte IPv6'],
            'benchmark v6' => ['2001:2::1', 'benchmark IPv6'],
            'ORCHID' => ['2001:10::1', 'ORCHID'],
            'fim do ORCHIDv2' => ['2001:2f::1', 'ORCHIDv2'],
            'documentação v6 nova' => ['3fff::1', 'documentação IPv6'],
            'SRv6' => ['5f00::1', 'SRv6'],
            'IPv4 traduzido do SIIT' => ['::ffff:0:a00:1', 'fora do espaço IPv6 global'],
            'v6 fora de 2000::/3' => ['4000::1', 'fora do espaço IPv6 global'],
        ];
    }

    #[DataProvider('blocked')]
    public function test_blocks(string $ip, string $reason): void
    {
        $this->assertFalse(IpGuard::isPublic($ip), $ip);
        $this->assertSame($reason, IpGuard::blockReason($ip));
    }

    /** @return array<string, array{0: string}> */
    public static function publicAddresses(): array
    {
        return [
            'logo antes de 172.16' => ['172.15.255.255'],
            'logo depois de 172.31' => ['172.32.0.0'],
            'logo antes do CGNAT' => ['100.63.255.255'],
            'logo depois do CGNAT' => ['100.128.0.0'],
            'logo antes do benchmark' => ['198.17.255.255'],
            'logo depois do benchmark' => ['198.20.0.0'],
            'logo antes de 10/8' => ['9.255.255.255'],
            'logo depois de 10/8' => ['11.0.0.0'],
            'DNS público' => ['1.1.1.1'],
            'v6 público' => ['2606:4700:4700::1111'],
            'IPv4 público mapeado em v6' => ['::ffff:8.8.8.8'],
            // As faixas novas não pegam endereço de site (o Teredo é 2001::/32, não 2001::/16).
            'v6 do Google' => ['2001:4860:4860::8888'],
            'v6 da Hurricane Electric' => ['2001:470::1'],
            'v6 da APNIC' => ['2001:200::1'],
            'v6 no Brasil' => ['2804:14c::1'],
            'v6 da AWS' => ['2600:1f18::1'],
            'v6 da Hostinger' => ['2a02:4780::1'],
            'logo depois do relay 6to4' => ['192.88.100.1'],
            'logo depois do ORCHIDv2 (só confere a máscara /28)' => ['2001:30::1'],
            // O IP do servidor do Security Monitor é público aqui de propósito: a monitoria do admin lê os sites que
            // resolvem para ele (prova "servidor"). Quem não pode visitá-lo recebe a lista à parte. Não pode ser um
            // dos endereços de documentação (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24): o IpGuard os trata como
            // reservados, não públicos, então o exemplo precisa de um IP fora dessas faixas.
            'IP do servidor do Security Monitor' => ['45.33.12.10'],
        ];
    }

    #[DataProvider('publicAddresses')]
    public function test_lets_public_addresses_through(string $ip): void
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

    #[DataProvider('disguised')]
    public function test_refuses_what_is_not_a_plain_ip_literal(string $value): void
    {
        $this->assertSame('endereço inválido', IpGuard::blockReason($value), $value);
        $this->assertFalse(IpGuard::isPublic($value));
    }
}
