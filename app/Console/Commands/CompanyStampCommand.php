<?php

namespace App\Console\Commands;

use App\Services\Company\CompanyStamp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CompanyStampCommand extends Command
{
    protected $signature = 'secmon:company-stamp {--dry-run : só conta o que seria carimbado}';

    protected $description = 'Carimba com a empresa de quem criou (sem setor) os alertas, Radares, monitoramentos do GitHub a posse dos domínios e os canais de aviso de conta de empresa ainda sem empresa (E2g, E3a2, E5b1); a conta pessoal não muda';

    public function handle(CompanyStamp $stamp): int
    {
        $dry = (bool) $this->option('dry-run');
        $counts = $stamp->run($dry);

        $this->info(($dry ? 'Seriam carimbados: ' : 'Carimbados: ')
            ."{$counts['alertas']} alerta(s), {$counts['radares']} Radar(es), {$counts['github']} monitoramento(s) do GitHub.");

        // A posse dos domínios (E3a2), depois dos itens: as cópias dos itens carimbados passam a mostrar o TXT da empresa.
        $domains = $stamp->domains($dry);
        $this->info(($dry ? 'Domínios que seriam passados à empresa: ' : 'Domínios passados à empresa: ')
            ."{$domains['dominios']}; linhas repetidas ".($dry ? 'que sairiam' : 'que saíram').": {$domains['repetidos']}.");

        // Os canais de aviso (E5b1): os da admin ativa passam a gerais da empresa; a conversa privada e os de outras contas saem.
        $channels = $stamp->channels($dry);
        $this->info(($dry ? 'Canais que passariam a gerais da empresa: ' : 'Canais passados a gerais da empresa: ')
            ."{$channels['canais']}; conversas privadas e canais de quem não administra ".($dry ? 'que sairiam' : 'que saíram').": {$channels['removidos']}.");

        if (! $dry && array_sum($counts) + array_sum($domains) + array_sum($channels) > 0) {
            Log::info('secmon:company-stamp', $counts + $domains + $channels);
        }

        return self::SUCCESS;
    }
}
