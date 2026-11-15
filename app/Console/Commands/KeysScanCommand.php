<?php

namespace App\Console\Commands;

use App\Services\Keys\KeySites;
use Illuminate\Console\Command;

/** A varredura semanal de chaves: pede a de cada domínio ativado com a vez vencida. */
class KeysScanCommand extends Command
{
    protected $signature = 'secmon:keys-scan {--due : só os domínios com a vez vencida (o agendador)}';

    protected $description = 'Pede a varredura de chaves dos domínios comprovados ativados, na vez de cada um';

    public function handle(KeySites $sites): int
    {
        $this->info($sites->due().' varredura(s) de chaves pedida(s).');

        return self::SUCCESS;
    }
}
