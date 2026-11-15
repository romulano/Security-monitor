<?php

namespace App\Services\Alerts\Telegram;

/** Resposta do Telegram já classificada: entregue, tentar de novo ou desistir. */
final class TelegramResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly ?string $error = null,
        public readonly ?int $retryAfter = null,
        public readonly bool $permanent = false,
        public readonly bool $breaksChannel = false,
        public readonly mixed $data = null,
    ) {}

    public static function ok(int $status, mixed $data = null): self
    {
        return new self(true, $status, data: $data);
    }

    public static function rateLimited(int $status, int $seconds): self
    {
        return new self(false, $status, 'rate_limited', retryAfter: max(1, $seconds));
    }

    public static function transient(int $status, string $error): self
    {
        return new self(false, $status, $error);
    }

    /** O grupo virou supergrupo: `data` traz o ID novo da conversa. */
    public static function migrated(int $status, string $chatId): self
    {
        return new self(false, $status, 'migrated', data: $chatId);
    }

    /** Não adianta repetir. Com $breaksChannel, o cliente precisa reconectar. */
    public static function permanent(int $status, string $error, bool $breaksChannel): self
    {
        return new self(false, $status, $error, permanent: true, breaksChannel: $breaksChannel);
    }

    /** Mensagem para o cliente e o admin. */
    public function humanError(): string
    {
        return match ($this->error) {
            'blocked' => 'A conversa com o bot do Security Monitor foi bloqueada no Telegram. Desbloqueie o bot ou conecte de novo.',
            'kicked' => 'O bot do Security Monitor foi removido do grupo.',
            'chat_not_found' => 'O Telegram não encontrou a conversa (o grupo foi apagado ou o bot saiu dele).',
            'no_rights' => 'O bot do Security Monitor não tem permissão para escrever nesse grupo.',
            'deactivated' => 'A conta do Telegram que recebia os alertas foi desativada.',
            'forbidden' => 'O Telegram não deixou o bot do Security Monitor escrever nessa conversa.',
            'unauthorized' => 'O Telegram recusou o token do bot do Security Monitor. Avise o suporte.',
            'no_target' => 'Falta conectar uma conversa do Telegram.',
            'not_configured' => 'O bot do Security Monitor no Telegram não está configurado.',
            'connection' => 'Não foi possível falar com o Telegram.',
            default => 'Falha ao falar com o Telegram ('.$this->error.').',
        };
    }
}
