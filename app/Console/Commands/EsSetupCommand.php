<?php

namespace App\Console\Commands;

use App\Services\Alerts\ProfileIndex;
use App\Services\Search\CaseIndex;
use Illuminate\Console\Command;

class EsSetupCommand extends Command
{
    protected $signature = 'secmon:es-setup {--fresh : apaga e recria o índice}';

    protected $description = 'Cria (ou recria) o índice do Elasticsearch dos casos e o índice de perfis de alerta';

    public function handle(CaseIndex $index, ProfileIndex $profiles): int
    {
        if (! $index->ping()) {
            $this->error('Elasticsearch inacessível em '.config('secmon.elasticsearch.host'));

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $index->drop();
            $this->line('Índice anterior removido.');
        }

        $index->create();
        $this->addMissingFields($index, $index->index());
        $this->info('Índice "'.$index->index().'" pronto.');
        $this->pruneHidden($index);

        // O percolator copia o mapping dos casos: se este foi recriado, o de
        // perfis precisa acompanhar (e os perfis são regravados com
        // secmon:alerts-recompile).
        if ($this->option('fresh')) {
            $profiles->drop();
        }

        $profiles->create();
        $this->addMissingFields($index, $profiles->name());
        $this->info('Índice de perfis "'.$profiles->name().'" pronto.');

        return self::SUCCESS;
    }

    /**
     * Matéria escondida direto no banco (uma migration) sai da busca aqui: o
     * boot roda este comando logo depois das migrations.
     */
    private function pruneHidden(CaseIndex $index): void
    {
        try {
            if ($pruned = $index->pruneHidden()) {
                $this->line("{$pruned} matéria(s) escondida(s) do feed público saíram da busca.");
            }
        } catch (\Throwable $e) {
            $this->warn("Não consegui tirar da busca as matérias escondidas: {$e->getMessage()}");
        }
    }

    /** Campo novo no código (ex.: has_exploit) entra no índice que já existe. */
    private function addMissingFields(CaseIndex $index, string $name): void
    {
        try {
            if ($added = $index->addMissingFields($name, $index->properties())) {
                $this->line("Campos novos em \"{$name}\": ".implode(', ', $added));
            }
        } catch (\Throwable $e) {
            $this->warn("Não consegui atualizar o mapping de \"{$name}\": {$e->getMessage()}");
        }
    }
}
