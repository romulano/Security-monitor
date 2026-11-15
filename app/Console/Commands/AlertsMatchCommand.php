<?php

namespace App\Console\Commands;

use App\Services\Alerts\Rematcher;
use Illuminate\Console\Command;

class AlertsMatchCommand extends Command
{
    protected $signature = 'secmon:alerts-match
        {--since=24h : janela de casos (24h, 7d, 90m)}
        {--profile= : só este perfil}
        {--notify : manda no Slack o que casar (padrão: só registra)}
        {--sync : roda aqui em vez de enfileirar}';

    protected $description = 'Passa os casos recentes pelo matching dos perfis de alerta';

    public function handle(Rematcher $rematcher): int
    {
        try {
            $since = Rematcher::since((string) $this->option('since'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $count = $rematcher->run(
            $since,
            $this->option('profile') ? (int) $this->option('profile') : null,
            (bool) $this->option('notify'),
            (bool) $this->option('sync'),
        );

        $this->info(sprintf('%d casos %s desde %s.', $count, $this->option('sync') ? 'avaliados' : 'enfileirados', $since->timezone('America/Sao_Paulo')->format('d/m H:i')));

        return self::SUCCESS;
    }
}
