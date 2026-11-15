<?php

namespace App\Console\Commands;

use App\Services\Alerts\Telegram\TelegramClient;
use App\Services\Alerts\Telegram\UpdateHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Busca o que chegou para o bot do Telegram (long polling) e trata: o /start
 * de quem conecta e a entrada/saída do bot nas conversas. Roda no supervisor
 * e sai depois de --max-time, como os workers das filas. Sem webhook, não há
 * endpoint público para o Telegram chamar.
 */
class TelegramUpdatesCommand extends Command
{
    /** Próxima mensagem a buscar: o Telegram descarta as anteriores a ela. */
    public const OFFSET = 'telegram-updates-offset';

    protected $signature = 'secmon:telegram-updates
        {--max-time=3600 : Segundos até sair (o supervisor sobe de novo)}
        {--once : Uma busca só, sem esperar}';

    protected $description = 'Recebe as mensagens do bot do Telegram (conexão dos clientes)';

    public function handle(TelegramClient $telegram, UpdateHandler $handler): int
    {
        $once = (bool) $this->option('once');
        $deadline = time() + max(60, (int) $this->option('max-time'));
        $stop = false;

        $this->trap([SIGTERM, SIGINT], function () use (&$stop) {
            $stop = true;
        });

        if (! TelegramClient::configured()) {
            $this->warn('Sem TELEGRAM_BOT_TOKEN no .env: o bot do Telegram fica desligado.');

            // Sair na hora faria o supervisor reiniciar sem parar: espera o fim do ciclo.
            while (! $once && ! $stop && time() < $deadline) {
                sleep(5);
            }

            return self::SUCCESS;
        }

        do {
            $updates = $telegram->updates(Cache::get(self::OFFSET), $once ? 0 : 50);

            if ($updates === null) {
                if ($once) {
                    $this->error('O Telegram não respondeu.');

                    return self::FAILURE;
                }

                sleep(5);

                continue;
            }

            foreach ($updates as $update) {
                try {
                    $handler->handle((array) $update);
                } catch (Throwable $e) {
                    report($e);
                }

                Cache::forever(self::OFFSET, (int) ($update['update_id'] ?? 0) + 1);
            }
        } while (! $once && ! $stop && time() < $deadline);

        return self::SUCCESS;
    }
}
