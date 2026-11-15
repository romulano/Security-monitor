<?php

namespace App\Services\Alerts\Discord;

/** Resposta do Discord já classificada: entregue, tentar de novo ou desistir. */
final class DiscordResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly ?string $error = null,
        public readonly ?int $retryAfter = null,
        public readonly bool $permanent = false,
        public readonly bool $breaksChannel = false,
        public readonly array $data = [],
    ) {}

    public static function ok(int $status, array $data = []): self
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

    /** Não adianta repetir. Com $breaksChannel, o cliente precisa reconectar. */
    public static function permanent(int $status, string $error, bool $breaksChannel): self
    {
        return new self(false, $status, $error, permanent: true, breaksChannel: $breaksChannel);
    }

    /** Mensagem para o cliente e o admin. */
    public function humanError(): string
    {
        return match ($this->error) {
            'discord_10003' => 'O canal não existe mais no Discord.',
            'discord_10004' => 'O servidor não existe mais ou removeu o bot do Security Monitor.',
            'discord_10015', 'invalid_webhook_token' => 'O webhook não existe mais no Discord.',
            'discord_50001' => 'O bot do Security Monitor não tem acesso a esse canal (saiu do servidor ou o canal é privado).',
            'discord_50013' => 'O bot do Security Monitor não tem permissão para escrever nesse canal.',
            'discord_50007' => 'O Discord não deixou o bot mandar mensagem direta para você. Libere mensagens diretas de membros do servidor nas configurações de privacidade do Discord ou use um canal.',
            'discord_50278' => 'O Discord só deixa o bot mandar mensagem direta para quem está em algum servidor junto com ele, e vocês não têm nenhum em comum. Entre na comunidade do Security Monitor no Discord ou receba num canal.',
            'discord_50035' => 'O Discord recusou o formato da mensagem.',
            'unauthorized', 'discord_40001' => 'O Discord recusou o token do bot do Security Monitor. Avise o suporte.',
            'invalid_webhook' => 'O endereço salvo não é um webhook do Discord.',
            'no_target' => 'Falta escolher o canal do Discord.',
            'not_configured' => 'O app do Security Monitor no Discord não está configurado.',
            'connection' => 'Não foi possível falar com o Discord.',
            default => 'Falha ao falar com o Discord ('.$this->error.').',
        };
    }
}
