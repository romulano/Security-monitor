<?php

namespace App\Console\Commands;

use App\Services\Alerts\Discord\DiscordGateway;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Conecta o bot do Discord ao gateway uma vez. O Discord exige isso de um bot
 * novo antes da primeira mensagem; depois disso o Security Monitor só usa a API HTTP.
 */
class DiscordIdentifyCommand extends Command
{
    protected $signature = 'secmon:discord-identify';

    protected $description = 'Conecta o bot do Discord ao gateway uma vez (exigido antes da primeira mensagem)';

    public function handle(DiscordGateway $gateway): int
    {
        $token = (string) config('secmon.alerts.discord.bot_token');

        if ($token === '') {
            $this->error('Sem DISCORD_BOT_TOKEN no .env.');

            return self::FAILURE;
        }

        try {
            $name = $gateway->identify($token);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Bot {$name} conectado ao gateway do Discord. Ele já pode mandar mensagens.");

        return self::SUCCESS;
    }
}
