<?php

namespace App\Services\Alerts\Webhook;

/**
 * O segredo de cada webhook: vai no cabeçalho Security-Monitor-Notification e é a chave da
 * assinatura Security-Monitor-Signature, no padrão do Stripe e do GitHub:
 *
 *     Security-Monitor-Signature: t=<unix>,v1=<hex do HMAC-SHA256 de "<t>.<corpo>">
 *
 * O receptor refaz o HMAC com o corpo exato que recebeu e recusa o que não bate
 * ou o que tem o `t` velho (5 minutos, contra a repetição de uma entrega antiga).
 *
 * Gerado: 32 bytes aleatórios em base64url, com o prefixo `secmon_whsec_` (o
 * prefixo deixa um segredo vazado fácil de achar). Escolhido pelo cliente: de 32 a
 * 128 caracteres de um alfabeto que cabe num cabeçalho e num .env sem aspas.
 */
final class WebhookSecret
{
    public const PREFIX = 'secmon_whsec_';

    public const MIN = 32;

    public const MAX = 128;

    /** A janela que o Security Monitor recomenda ao receptor para o `t` da assinatura, em segundos. */
    public const TOLERANCE = 300;

    public static function generate(): string
    {
        return self::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** O valor escolhido pelo cliente serve? Nulo se serve; senão, o motivo. */
    public static function problem(string $value): ?string
    {
        return match (true) {
            strlen($value) < self::MIN || strlen($value) > self::MAX => 'Use de '.self::MIN.' a '.self::MAX.' caracteres, ou peça para o Security Monitor gerar um.',
            ! preg_match('#^[A-Za-z0-9._~+/=-]+$#', $value) => 'Use só letras, números e . _ ~ + / = - (sem espaço nem aspas).',
            count(array_unique(str_split($value))) < 8 => 'Esse valor é fácil de adivinhar. Escolha outro ou peça para o Security Monitor gerar um.',
            default => null,
        };
    }

    /** O valor do cabeçalho Security-Monitor-Signature para este corpo, assinado em `$timestamp`. */
    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
