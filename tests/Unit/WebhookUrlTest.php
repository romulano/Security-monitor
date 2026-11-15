<?php

namespace Tests\Unit;

use App\Services\Alerts\Webhook\WebhookUrl;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** A URL do webhook: https:443 num domínio público, sem credencial, numa forma só. */
class WebhookUrlTest extends TestCase
{
    public function test_the_url_is_normalized(): void
    {
        $this->assertSame(['url' => 'https://hooks.empresa.com.br/secmon?x=1', 'host' => 'hooks.empresa.com.br'], WebhookUrl::normalize('  HTTPS://Hooks.Empresa.com.br.:443/secmon?x=1#trecho '));
        $this->assertSame('https://empresa.com.br/', WebhookUrl::normalize('https://empresa.com.br')['url']);
        $this->assertSame('https://empresa.com.br/?token=abc', WebhookUrl::normalize('https://empresa.com.br?token=abc')['url']);
        $this->assertSame('https://empresa.com.br/notifica%C3%A7%C3%A3o%20nova', WebhookUrl::normalize('https://empresa.com.br/notificação nova')['url']);
        $this->assertSame('xn--aes-2la4e.com.br', WebhookUrl::normalize('https://ações.com.br/x')['host']);
    }

    /** @return array<string, array{string, string}> */
    public static function refused(): array
    {
        return [
            'http' => ['http://empresa.com.br/secmon', 'Use https://'],
            'sem esquema' => ['empresa.com.br/secmon', 'precisa começar com https://'],
            'outro esquema' => ['ftp://empresa.com.br/', 'precisa começar com https://'],
            'usuário e senha' => ['https://user:pass@empresa.com.br/', 'usuário e a senha'],
            'arroba esperto' => ['https://empresa.com.br@evil.com/', 'usuário e a senha'],
            'IPv4' => ['https://203.0.113.7/hook', 'não um IP'],
            'IPv4 privado' => ['https://10.0.0.1/hook', 'não um IP'],
            'IPv6' => ['https://[::1]/hook', 'não um IP'],
            'IP em decimal' => ['https://2130706433/hook', 'domínio público'],
            'localhost' => ['https://localhost/hook', 'domínio público'],
            'TLD interno' => ['https://cofre.internal/hook', 'domínio público'],
            '.local' => ['https://nas.local/hook', 'domínio público'],
            'outra porta' => ['https://empresa.com.br:8443/hook', 'porta 443'],
            'porta vazia' => ['https://empresa.com.br:/hook', 'porta 443'],
            'o próprio Security Monitor' => ['https://example.com/alertas', 'próprio Security Monitor'],
            'subdomínio do Security Monitor' => ['https://api.example.com/x', 'próprio Security Monitor'],
            'quebra de linha' => ["https://empresa.com.br/a\r\nHost: evil.com", 'caractere inválido'],
            'longa demais' => ['https://empresa.com.br/'.str_repeat('a', 2000), '2.000 caracteres'],
            'vazia' => ['   ', 'Informe a URL'],
        ];
    }

    #[DataProvider('refused')]
    public function test_unsafe_urls_are_refused(string $url, string $message): void
    {
        try {
            WebhookUrl::normalize($url);
            $this->fail("{$url} deveria ser recusada");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
