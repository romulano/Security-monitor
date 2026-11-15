<?php

namespace App\Services\Alerts\Slack;

/** Resposta do Slack já classificada: entregue, tentar de novo ou desistir. */
final class SlackResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly ?string $error = null,
        public readonly ?int $retryAfter = null,
        public readonly bool $permanent = false,
        public readonly bool $breaksChannel = false,
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

    /** Não adianta repetir. Com $breaksChannel, o canal precisa ser reconectado. */
    public static function permanent(int $status, string $error, bool $breaksChannel): self
    {
        return new self(false, $status, $error, permanent: true, breaksChannel: $breaksChannel);
    }

    /** Mensagem para o cliente e o admin. */
    public function humanError(): string
    {
        return match ($this->error) {
            'no_service', 'no_active_hooks' => 'O webhook não existe mais no Slack (no_service).',
            'channel_not_found' => 'O canal não existe mais (channel_not_found).',
            'channel_is_archived' => 'O canal foi arquivado (channel_is_archived).',
            'action_prohibited', 'posting_to_general_channel_denied' => 'O Slack não permite postar nesse canal (action_prohibited).',
            'invalid_token', 'team_disabled', 'no_team' => 'A instalação do Slack foi removida ou desativada.',
            'invalid_webhook' => 'O endereço salvo não é um webhook do Slack.',
            'invalid_payload' => 'O Slack recusou o formato da mensagem (invalid_payload).',
            default => 'Falha ao falar com o Slack ('.$this->error.').',
        };
    }
}
