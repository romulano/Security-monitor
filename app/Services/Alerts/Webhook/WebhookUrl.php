<?php

namespace App\Services\Alerts\Webhook;

use App\Support\DomainName;
use InvalidArgumentException;

/**
 * A URL que o cliente cadastra no webhook, conferida e numa forma só:
 *
 * - https, na porta 443 (a padrão, que sai da URL): sem TLS, o segredo e o alerta
 *   iriam em claro; e outra porta de um servidor de terceiro é onde ficam banco,
 *   painel e SSH, que o Security Monitor não tem por que chamar;
 * - sem usuário:senha (a autenticação é o cabeçalho Security-Monitor-Notification) e sem o
 *   fragmento (#…), que nem chega ao servidor;
 * - o host com nome, nunca IP, num domínio público: é o nome que prova a posse
 *   (WebhookOwnership), e os TLDs internos (localhost, .local, .internal…) ficam de
 *   fora, como no Radar. O próprio Security Monitor também: ele não notifica a si mesmo;
 * - até 2.000 caracteres, sem caractere de controle; o que não é ASCII no caminho
 *   vira %XX, e o host em IDN vira punycode.
 *
 * O IP para onde o nome aponta é conferido em cada envio (WebhookClient).
 */
final class WebhookUrl
{
    public const MAX = 2000;

    /**
     * @return array{url: string, host: string}
     *
     * @throws InvalidArgumentException com a mensagem para o cliente
     */
    public static function normalize(?string $input): array
    {
        $value = trim((string) $input);

        if ($value === '') {
            throw new InvalidArgumentException('Informe a URL do webhook.');
        }

        if (strlen($value) > self::MAX) {
            throw new InvalidArgumentException('A URL passa de 2.000 caracteres.');
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('A URL tem um caractere inválido (quebra de linha ou de controle).');
        }

        if (! preg_match('#^https://([^/?\#]*)(.*)$#is', $value, $parts)) {
            throw new InvalidArgumentException(preg_match('#^http://#i', $value)
                ? 'Use https://: sem TLS, o alerta e o segredo iriam em claro.'
                : 'A URL precisa começar com https://.');
        }

        [, $authority, $rest] = $parts;

        if (str_contains($authority, '@')) {
            throw new InvalidArgumentException('Tire o usuário e a senha da URL: a autenticação vai no cabeçalho Security-Monitor-Notification.');
        }

        if (str_starts_with($authority, '[') || str_contains($authority, '\\')) {
            throw new InvalidArgumentException('Use o nome do domínio, não um IP.');
        }

        $host = $authority;

        if (str_contains($authority, ':')) {
            [$host, $port] = explode(':', $authority, 2);

            if ($port !== '443') {
                throw new InvalidArgumentException('Use a porta 443, a padrão do https (deixe a porta fora da URL).');
            }
        }

        $host = rtrim(mb_strtolower($host), '.');

        if (preg_match('/[^\x20-\x7e]/', $host) && function_exists('idn_to_ascii')) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('Use o nome do domínio, não um IP: é o domínio comprovado que autoriza o envio.');
        }

        if (! DomainName::isValid($host)) {
            throw new InvalidArgumentException('O endereço precisa de um domínio público, como api.empresa.com.br (sem localhost nem domínio interno).');
        }

        if (self::isSecurityMonitor($host)) {
            throw new InvalidArgumentException('O webhook não pode apontar para o próprio Security Monitor.');
        }

        // O fragmento não vai ao servidor; o caminho começa em "/"; o que não é ASCII visível vira %XX.
        $rest = preg_replace('/#.*$/s', '', $rest);
        $rest = preg_replace_callback('/[^\x21-\x7e]/', fn (array $m) => rawurlencode($m[0]), $rest);
        $rest = str_starts_with($rest, '/') ? $rest : '/'.$rest;
        $url = 'https://'.$host.$rest;

        if (strlen($url) > self::MAX) {
            throw new InvalidArgumentException('A URL passa de 2.000 caracteres.');
        }

        return ['url' => $url, 'host' => $host];
    }

    /** O domínio do Security Monitor e o do endereço do app (APP_URL), com os subdomínios. */
    private static function isSecurityMonitor(string $host): bool
    {
        foreach (array_filter(['example.com', parse_url((string) config('app.url'), PHP_URL_HOST)]) as $name) {
            $name = strtolower((string) $name);

            if ($host === $name || str_ends_with($host, '.'.$name)) {
                return true;
            }
        }

        return false;
    }
}
