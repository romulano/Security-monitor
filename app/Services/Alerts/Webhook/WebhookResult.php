<?php

namespace App\Services\Alerts\Webhook;

/**
 * Resposta do servidor do cliente já classificada: 2xx entregue; 5xx, tempo
 * esgotado e falha de rede tentam de novo; 429 espera o Retry-After; o resto dos
 * 4xx e o redirecionamento desistem na hora (repetir não muda a resposta).
 */
final class WebhookResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly ?string $error = null,
        public readonly ?int $retryAfter = null,
        public readonly bool $permanent = false,
        public readonly bool $breaksChannel = false,
        public readonly ?string $detail = null,
    ) {}

    public static function ok(int $status): self
    {
        return new self(true, $status);
    }

    public static function rateLimited(int $status, int $seconds): self
    {
        return new self(false, $status, 'rate_limited', retryAfter: max(1, $seconds));
    }

    public static function transient(int $status, string $error): self
    {
        return new self(false, $status, $error);
    }

    /** Não adianta repetir. Com $breaksChannel, nada mais sai até o cliente resolver (o teste continua saindo). */
    public static function permanent(int $status, string $error, bool $breaksChannel = false, ?string $detail = null): self
    {
        return new self(false, $status, $error, permanent: true, breaksChannel: $breaksChannel, detail: $detail);
    }

    /** Mensagem para o cliente e o admin: nunca a URL, que pode levar um token. */
    public function humanError(): string
    {
        return match ($this->error) {
            'unproven' => 'A posse do domínio do webhook não está comprovada.',
            'invalid_url' => 'A URL do webhook deixou de ser aceita. Cadastre o endereço de novo.',
            'dns' => 'O domínio do webhook não respondeu no DNS.',
            'blocked' => 'O domínio do webhook aponta para um endereço que o Security Monitor não chama ('.($this->detail ?? 'rede interna').').',
            'timeout' => 'O seu servidor não respondeu a tempo ('.(int) config('secmon.alerts.webhook.timeout', 10).' segundos).',
            'connection' => 'Não foi possível conectar ao seu servidor (rede ou certificado TLS).',
            'redirect' => "O seu servidor respondeu com um redirecionamento (HTTP {$this->status}). O Security Monitor não segue redirecionamentos: cadastre a URL final.",
            'gone' => 'O seu servidor respondeu que o endereço não existe mais (HTTP 410).',
            'refused' => "O seu servidor recusou a notificação (HTTP {$this->status}).",
            'server' => "O seu servidor respondeu com erro (HTTP {$this->status}).",
            'rate_limited' => 'O seu servidor pediu para esperar (HTTP 429).',
            default => 'Falha ao entregar no webhook ('.$this->error.').',
        };
    }
}
