<?php

namespace App\Console\Commands;

use App\Services\Company\CompanyRemoval;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CompanyPurgeCommand extends Command
{
    protected $signature = 'secmon:company-purge {--dry-run : só conta o que seria apagado}';

    protected $description = 'Apaga as contas de empresa desativadas há mais de 30 dias (§4.6, D9); mantém a que tem registro de teste de segurança';

    public function handle(CompanyRemoval $removal): int
    {
        $dry = (bool) $this->option('dry-run');
        $count = $removal->purge($dry);

        $this->info(($dry ? 'Seriam apagadas: ' : 'Apagadas: ').$count);

        foreach ($removal->held() as $userId) {
            $this->line("Mantida: tem registro de teste (conta #{$userId})");
        }

        if (! $dry && $count > 0) {
            Log::info('secmon:company-purge', ['apagadas' => $count]);
        }

        return self::SUCCESS;
    }
}
