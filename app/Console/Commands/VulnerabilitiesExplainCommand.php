<?php

namespace App\Console\Commands;

use App\Jobs\ExplainVulnerability;
use App\Models\NewsCase;
use App\Services\Alerts\Rematcher;
use App\Services\Vulnerability\VulnerabilityExplainer;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Explicação da falha para as matérias de vulnerabilidade que ainda não têm
 * (as de antes desta etapa existir, as que falharam). Com --sync roda aqui
 * mesmo e mostra o resultado; sem, põe na fila `default`, sem esperar.
 */
class VulnerabilitiesExplainCommand extends Command
{
    protected $signature = 'secmon:vulns-explain
        {--case=* : só estas matérias (uuid)}
        {--since= : só as atualizadas desde (24h, 7d)}
        {--again : refaz também as que já têm explicação}
        {--dry-run : só lista as matérias}
        {--sync : roda agora, sem fila}';

    protected $description = 'Gera pela IA a explicação da falha e de como ela foi descoberta nas matérias de vulnerabilidade';

    public function handle(VulnerabilityExplainer $explainer): int
    {
        if (! $explainer->enabled() && ! $this->option('dry-run')) {
            $this->error('A explicação está desligada (VULN_EXPLAIN=false) ou o hermes não está configurado.');

            return self::FAILURE;
        }

        try {
            $since = $this->option('since') ? Rematcher::since((string) $this->option('since')) : null;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $cases = NewsCase::query()
            ->where('is_published', true)
            ->when($this->option('case'), fn ($q, $uuids) => $q->whereIn('uuid', $uuids))
            ->when($since, fn ($q, $at) => $q->where('latest_at', '>=', $at))
            ->when(! $this->option('again'), fn ($q) => $q->whereNull('vulnerability_explained_at'))
            ->orderByDesc('latest_at')
            ->get()
            ->filter(fn (NewsCase $case) => VulnerabilityExplainer::candidate($case))
            ->values();

        if ($cases->isEmpty()) {
            $this->line('Nada a explicar.');

            return self::SUCCESS;
        }

        foreach ($cases as $case) {
            if ($this->option('dry-run')) {
                $this->line($case->uuid.'  '.mb_strimwidth((string) $case->title, 0, 110, '…'));

                continue;
            }

            // Com --again, refaz mesmo sem mudança no que a explicação lê.
            $force = (bool) $this->option('again');

            if (! $this->option('sync')) {
                ExplainVulnerability::dispatch($case->id, $force);

                continue;
            }

            app()->call([new ExplainVulnerability($case->id, $force), 'handle']);
            $case->refresh();
            $this->line($case->uuid.'  '.match (true) {
                filled($case->vulnerability_explain_error) => 'falhou: '.$case->vulnerability_explain_error,
                ! $case->vulnerability_applies => 'não é sobre uma falha',
                default => mb_substr((string) $case->vulnerability_explanation_pt, 0, 160),
            });
        }

        $this->info(match (true) {
            (bool) $this->option('dry-run') => "{$cases->count()} matéria(s) de vulnerabilidade.",
            (bool) $this->option('sync') => "{$cases->count()} matéria(s) processada(s).",
            default => "{$cases->count()} explicação(ões) na fila default.",
        });

        return self::SUCCESS;
    }
}
