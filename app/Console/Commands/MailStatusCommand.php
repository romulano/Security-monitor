<?php

namespace App\Console\Commands;

use App\Services\Mail\DeliveryLog;
use Illuminate\Console\Command;

class MailStatusCommand extends Command
{
    protected $signature = 'secmon:mail-status';

    protected $description = 'Lê o log do servidor de envio e marca cada e-mail como entregue, adiado ou devolvido';

    public function handle(DeliveryLog $log): int
    {
        $this->info($log->sync().' linha(s) de entrega aplicada(s).');

        return self::SUCCESS;
    }
}
