<?php

namespace App\Services\Github;

use App\Jobs\ReviewGithubFinding;
use App\Models\GithubFinding;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O caminho de um achado depois da varredura, em duas etapas com prompts
 * separados, e a segunda só começa se a primeira disser que é achado:
 *
 * 1. classificação (GithubTriage): nível e certeza. `none` = descartado; nível
 *    abaixo do escolhido pelo cliente = fica na tela, sem a etapa 2;
 * 2. parecer de envio (GithubJudge): acima do corte vai para os apps; no corte
 *    ou abaixo fica "segurado pela IA".
 *
 * Sem IA (desligada ou fora do ar), a etapa 1 usa o nível pelas regras e a
 * etapa 2 deixa o achado sair: perder um vazamento custa mais que um aviso a mais.
 */
class FindingFlow
{
    public function __construct(private GithubTriage $triage, private GithubJudge $judge, private GithubAlertRouter $router) {}

    public function triage(GithubFinding $finding): void
    {
        $monitor = $finding->monitor;

        if ($this->triage->enabled()) {
            try {
                // Uma resposta ruim isolada acontece; a segunda costuma vir certa.
                ['level' => $level, 'confidence' => $confidence, 'reason' => $reason] = retry(2, fn () => $this->triage->classify($finding));
                $finding->forceFill(['triage_level' => $level, 'triage_score' => $confidence, 'triage_reason' => $reason ?: null, 'triage_at' => now()]);
            } catch (Throwable $e) {
                Log::warning('Classificação da IA do GitHub indisponível; vale o nível pelas regras', ['finding' => $finding->id, 'error' => $e->getMessage()]);
                $finding->forceFill(['triage_level' => null, 'triage_score' => null, 'triage_at' => now(),
                    'triage_reason' => mb_substr('Sem classificação da IA ('.$e->getMessage().'); nível pelas regras automáticas.', 0, 500)]);
            }
        } else {
            $finding->forceFill(['triage_level' => null, 'triage_score' => null, 'triage_reason' => null, 'triage_at' => now()]);
        }

        $level = $finding->level();

        if ($level === 'none') {
            $finding->forceFill(['status' => 'discarded', 'note' => null])->save();

            return;
        }

        if (! $monitor->reaches($level)) {
            $finding->forceFill(['status' => 'below_level', 'note' => null])->save();

            return;
        }

        $finding->forceFill(['status' => 'ai_review', 'note' => null])->save();
        ReviewGithubFinding::dispatch($finding->id);
    }

    public function review(GithubFinding $finding): void
    {
        if (! $this->judge->enabled()) {
            $this->router->route($finding);

            return;
        }

        try {
            ['relevance' => $relevance, 'reason' => $reason] = retry(2, fn () => $this->judge->review($finding));
        } catch (Throwable $e) {
            Log::warning('Parecer da IA do GitHub indisponível; achado enviado sem parecer', ['finding' => $finding->id, 'error' => $e->getMessage()]);
            $finding->forceFill(['ai_reason' => mb_substr('Sem parecer da IA ('.$e->getMessage().'); enviado assim mesmo.', 0, 500), 'ai_reviewed_at' => now()])->save();
            $this->router->route($finding);

            return;
        }

        $finding->forceFill(['ai_score' => $relevance, 'ai_reason' => $reason ?: null, 'ai_reviewed_at' => now()])->save();

        if ($relevance > $this->judge->threshold()) {
            $this->router->route($finding);
        } else {
            $finding->forceFill(['status' => 'held', 'note' => 'ai_low_relevance'])->save();
        }
    }
}
