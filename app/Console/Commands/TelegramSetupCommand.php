<?php

namespace App\Console\Commands;

use App\Services\Alerts\Telegram\TelegramClient;
use Illuminate\Console\Command;

/**
 * Confere o token do bot do Telegram e grava o perfil dele: comandos,
 * descrição (o que aparece antes do primeiro /start) e o "sobre". Também
 * desliga qualquer webhook, porque o Security Monitor recebe por long polling.
 */
class TelegramSetupCommand extends Command
{
    private const DESCRIPTION = 'Alertas de inteligência de ameaças do Security Monitor (example.com): quando sai uma notícia de segurança '
        ."que afeta a stack da sua empresa, ela chega aqui, na hora ou num resumo diário.\n\n"
        .'Para conectar, entre no Security Monitor e abra Alertas → Notificadores → Telegram.';

    private const ABOUT = 'Alertas do Security Monitor (example.com): as ameaças que afetam a sua stack, na hora ou num resumo diário.';

    protected $signature = 'secmon:telegram-setup';

    protected $description = 'Confere o token do bot do Telegram e grava comandos, descrição e "sobre"';

    public function handle(TelegramClient $telegram): int
    {
        if (! TelegramClient::configured()) {
            $this->error('Sem TELEGRAM_BOT_TOKEN no .env.');

            return self::FAILURE;
        }

        $me = $telegram->call('getMe');

        if (! $me->ok) {
            $this->error('O Telegram recusou o token: '.$me->humanError());

            return self::FAILURE;
        }

        $username = (string) ($me->data['username'] ?? '');

        if (strcasecmp($username, TelegramClient::username()) !== 0) {
            $this->error("O token é do @{$username}, mas TELEGRAM_BOT_USERNAME diz @".TelegramClient::username().'.');

            return self::FAILURE;
        }

        $steps = [
            'deleteWebhook' => [],
            'setMyCommands' => ['commands' => [
                ['command' => 'start', 'description' => 'Conectar esta conversa aos alertas do Security Monitor'],
                ['command' => 'ajuda', 'description' => 'Como receber os alertas do Security Monitor'],
            ]],
            'setMyDescription' => ['description' => self::DESCRIPTION],
            'setMyShortDescription' => ['short_description' => self::ABOUT],
        ];

        $failed = false;

        foreach ($steps as $method => $params) {
            $result = $telegram->call($method, $params);
            $failed = $failed || ! $result->ok;
            $result->ok ? $this->line("✓ {$method}") : $this->error("✗ {$method}: ".$result->humanError());
        }

        // O bot não lê as conversas dos grupos (promessa da página de privacidade).
        if ($me->data['can_read_all_group_messages'] ?? false) {
            $this->warn('O modo de privacidade está desligado: no @BotFather, /setprivacy → @'.$username.' → Enable.');
            $failed = true;
        }

        if (! ($me->data['can_join_groups'] ?? true)) {
            $this->warn('O bot não pode entrar em grupos: no @BotFather, /setjoingroups → @'.$username.' → Enable.');
        }

        if ($failed) {
            return self::FAILURE;
        }

        $this->info("Bot @{$username} pronto.");

        return self::SUCCESS;
    }
}
