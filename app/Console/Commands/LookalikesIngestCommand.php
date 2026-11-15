<?php

namespace App\Console\Commands;

use App\Services\Lookalike\CaptureIngestor;
use Illuminate\Console\Command;

class LookalikesIngestCommand extends Command
{
    protected $signature = 'secmon:lookalikes-ingest
        {--rescore : recalcula situação e nível de todos pela última visita (depois de mudar as regras)}';

    protected $description = 'Lê as respostas do navegador do Radar de domínios (prints e sinais) e recalcula o nível de cada domínio';

    public function handle(CaptureIngestor $ingestor): int
    {
        if ($this->option('rescore')) {
            $this->info($ingestor->rescore().' domínio(s) mudaram de nível com as regras atuais.');

            return self::SUCCESS;
        }

        $result = $ingestor->ingest();

        $this->info("{$result['captures']} visita(s) lida(s), {$result['changed']} mudança(s) de nível"
            .($result['expired'] ? ", {$result['expired']} pedido(s) sem resposta dado(s) como falha" : '').'.');

        return self::SUCCESS;
    }
}
