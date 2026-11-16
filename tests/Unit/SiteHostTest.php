<?php

namespace Tests\Unit;

use App\Services\Security\SiteHost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O que o dono pode digitar para monitorar. Instalação local, de um dono só (15/11):
 * aceita qualquer host, inclusive IP (em qualquer forma), `localhost` e nome de
 * container — ele decide o alvo. Só sobra a sanitização que não é sobre o alvo (esquema,
 * caminho, espaço, caractere de controle, userinfo/porta).
 */
class SiteHostTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function accepted(): array
    {
        return [
            'nome simples' => ['exemplo.com.br', 'exemplo.com.br'],
            'com esquema e caminho' => ['https://Exemplo.com.br/login?a=1#x', 'exemplo.com.br'],
            'mantém o www (é outro site)' => ['www.exemplo.com', 'www.exemplo.com'],
            'subdomínio' => ['app.painel.exemplo.com', 'app.painel.exemplo.com'],
            'ponto final' => ['exemplo.com.', 'exemplo.com'],
            'espaços em volta' => ["  exemplo.com \n", 'exemplo.com'],
            'punycode' => ['xn--exemplo-9ua.com', 'xn--exemplo-9ua.com'],
            'nome que parece interno mas é público' => ['127.0.0.1.nip.io', '127.0.0.1.nip.io'],
            // O dono escolhe o alvo: IP em qualquer forma, localhost e nome de container
            // deixaram de ser recusados aqui (quem ainda confere faixa de IP é o IpGuard).
            'IPv4' => ['127.0.0.1', '127.0.0.1'],
            'IPv4 privado' => ['10.0.0.5', '10.0.0.5'],
            'metadata da nuvem' => ['169.254.169.254', '169.254.169.254'],
            'hexadecimal' => ['0x7f000001', '0x7f000001'],
            'decimal' => ['2130706433', '2130706433'],
            'octal' => ['017700000001', '017700000001'],
            'forma curta' => ['127.1', '127.1'],
            'localhost' => ['localhost', 'localhost'],
            'nome de container' => ['secmon-db', 'secmon-db'],
            'es' => ['es', 'es'],
            'hermes' => ['hermes', 'hermes'],
            'TLD interno' => ['servidor.internal', 'servidor.internal'],
            'TLD local' => ['impressora.local', 'impressora.local'],
            'um rótulo só' => ['exemplo', 'exemplo'],
        ];
    }

    #[DataProvider('accepted')]
    public function test_accepts_a_host(string $input, string $expected): void
    {
        $this->assertSame($expected, SiteHost::normalize($input));
    }

    /** @return array<string, array{0: ?string}> */
    public static function refused(): array
    {
        return [
            'vazio' => [''],
            'nulo' => [null],
            // Colchete e dois-pontos continuam fora: userinfo e porta não fazem sentido
            // no campo "host" (e um IPv6 literal precisaria dos dois-pontos para se escrever).
            'IPv6' => ['[::1]'],
            'IPv6 sem colchete' => ['::1'],
            'com porta' => ['exemplo.com:9200'],
            'com usuário e senha' => ['admin:senha@exemplo.com'],
            'só usuário' => ['admin@exemplo.com'],
            'quebra de linha injetada' => ["exemplo.com\r\nX-Injetado: 1"],
            'com espaço no meio' => ['exemplo .com'],
            'barra invertida' => ['exemplo.com\\@interno'],
            'caractere de controle' => ["exemplo\x00.com"],
            // Sublinhado nunca foi caractere de host (nem IP, nem DNS): é formato, não SSRF.
            'sublinhado' => ['meu_site.exemplo.com'],
            'só esquema' => ['https://'],
        ];
    }

    #[DataProvider('refused')]
    public function test_refuses(?string $input): void
    {
        $this->assertNull(SiteHost::normalize($input), (string) $input);
    }
}
