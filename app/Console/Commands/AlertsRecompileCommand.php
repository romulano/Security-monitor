<?php

namespace App\Console\Commands;

use App\Models\AlertProfile;
use App\Services\Alerts\InterviewService;
use Illuminate\Console\Command;

/**
 * Refaz todos os perfis a partir das respostas (com as regras atuais, como
 * resposta escrita que existe no catálogo) e regrava o percolator. Necessário
 * depois de `secmon:es-setup --fresh` (o índice de perfis é recriado vazio).
 */
class AlertsRecompileCommand extends Command
{
    protected $signature = 'secmon:alerts-recompile';

    protected $description = 'Recompila as queries dos perfis de alerta e regrava o percolator';

    public function handle(InterviewService $interview): int
    {
        $count = 0;

        AlertProfile::query()->whereNotNull('completed_at')->each(function (AlertProfile $profile) use ($interview, &$count) {
            $interview->rebuild($profile);
            $count++;
        });

        $this->info("{$count} perfil(is) recompilado(s).");

        return self::SUCCESS;
    }
}
