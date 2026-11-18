<?php

namespace App\Console\Commands;

use App\Services\Leaks\LeakImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Carrega um dump de vazamento da pasta de vazamentos (storage/app/private/vazamentos).
 *
 *   php artisan leaks:import Amostra2026 dumps/amostra2026.txt
 *   php artisan leaks:import "Collection #1" dumps/c1.txt --leaked-at=2019-01-07
 */
class LeaksImportCommand extends Command
{
    protected $signature = 'leaks:import
        {source : o nome do dump, como vai ficar gravado na coluna source}
        {path : o caminho do .txt dentro do disco}
        {--disk=leaks : o disco de onde ler}
        {--leaked-at= : a data do vazamento, quando se sabe (AAAA-MM-DD)}';

    protected $description = 'Lê um dump de credenciais da pasta de vazamentos e guarda só as credenciais de empresa';

    public function handle(LeakImporter $importer): int
    {
        $source = (string) $this->argument('source');
        $path = (string) $this->argument('path');

        $this->info("Lendo {$path} do disco {$this->option('disk')} como \"{$source}\"...");

        try {
            $tally = $importer->import(
                $source,
                $path,
                (string) $this->option('disk'),
                $this->option('leaked-at') ? (string) $this->option('leaked-at') : null,
                fn (array $t) => $this->getOutput()->write("\r  {$t['linhas']} linhas · {$t['salvas']} salvas · {$t['humano']} para o humano"),
            );
        } catch (Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine(2);
        $this->table(['o que', 'quantas'], [
            ['linhas lidas', number_format($tally['linhas'], 0, ',', '.')],
            ['guardadas (empresa)', number_format($tally['salvas'], 0, ',', '.')],
            ['descartadas: webmail genérico', number_format($tally['genericas'], 0, ',', '.')],
            ['descartadas: login não é e-mail', number_format($tally['sem_email'], 0, ',', '.')],
            ['para o humano classificar', number_format($tally['humano'], 0, ',', '.')],
            ['repetidas (já estavam)', number_format($tally['repetidas'], 0, ',', '.')],
            ['linhas em branco', number_format($tally['vazias'], 0, ',', '.')],
        ]);

        if ($tally['humano'] > 0) {
            $this->warn("{$tally['humano']} linha(s) esperam decisão em Admin → Vazamentos.");
        }

        return self::SUCCESS;
    }
}
