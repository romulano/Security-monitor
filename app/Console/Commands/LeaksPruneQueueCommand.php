<?php

namespace App\Console\Commands;

use App\Models\LeakImportLine;
use App\Services\Leaks\CredentialLineParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tira da fila do humano o que ele não teria como resolver.
 *
 * A carga antiga mandava para a quarentena toda linha que o parser não leu, sem perguntar se ela
 * teria salvação. Linha sem e-mail de empresa não tem: a tabela exige e-mail e webmail genérico é
 * descartado por regra, então o admin abriria, pensaria e só poderia descartar.
 *
 * A carga nova já não enfileira essas. Este comando limpa o que entrou antes — e serve de novo
 * depois de o admin mexer na lista de genéricos, porque aí o que conta como "de empresa" muda.
 *
 *   php artisan leaks:limpar-fila            # só conta
 *   php artisan leaks:limpar-fila --aplicar  # descarta de verdade
 */
class LeaksPruneQueueCommand extends Command
{
    protected $signature = 'leaks:limpar-fila
        {--aplicar : descarta de verdade; sem isto o comando só conta}
        {--source= : limita a um dump}';

    protected $description = 'Apaga da fila as linhas que nenhum humano poderia transformar em credencial';

    public function handle(CredentialLineParser $parser): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $inuteis = $uteis = 0;
        $exemplos = [];

        LeakImportLine::query()
            ->where('status', 'pending')
            ->when($this->option('source'), fn ($q, $s) => $q->where('source', $s))
            ->orderBy('id')
            ->chunkById(500, function ($linhas) use ($parser, $aplicar, &$inuteis, &$uteis, &$exemplos): void {
                $apagar = [];

                foreach ($linhas as $linha) {
                    if ($parser->parse($linha->raw)['company_email'] !== null) {
                        $uteis++;

                        continue;
                    }

                    $inuteis++;
                    $apagar[] = $linha->id;

                    if (count($exemplos) < 5) {
                        $exemplos[] = mb_substr($linha->raw, 0, 90);
                    }
                }

                if ($aplicar && $apagar !== []) {
                    DB::table('leak_import_lines')->whereIn('id', $apagar)->delete();
                }
            });

        $this->newLine();
        $this->table(['o que', 'quantas'], [
            ['linhas que um humano resolve (ficam)', number_format($uteis, 0, ',', '.')],
            ['linhas sem e-mail de empresa (saem)', number_format($inuteis, 0, ',', '.')],
        ]);

        if ($exemplos !== []) {
            $this->line('  exemplos do que sai:');
            foreach ($exemplos as $exemplo) {
                $this->line('    '.$exemplo);
            }
        }

        if (! $aplicar && $inuteis > 0) {
            $this->newLine();
            $this->warn('Nada foi alterado. Rode de novo com --aplicar para descartar essas '.$inuteis.'.');
        }

        return self::SUCCESS;
    }
}
