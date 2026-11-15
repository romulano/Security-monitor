<?php

namespace App\Console\Commands;

use App\Models\AlertMatch;
use App\Services\Alerts\AlertReviewer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Parecer da IA para alertas que já estão em Meus alertas (os de antes do juiz,
 * por exemplo). Só grava a nota e o motivo: nunca passa pelo AlertRouter, então
 * nenhuma mensagem sai. O que fica no corte ou abaixo vira "segurado pela IA";
 * o que já tinha sido enviado continua registrado como enviado.
 */
class AlertsReviewCommand extends Command
{
    protected $signature = 'secmon:alerts-review
        {--profile= : só os alertas deste perfil (id)}
        {--again : refaz também os que já têm parecer}
        {--dry-run : mostra o parecer sem gravar}';

    protected $description = 'Dá o parecer da IA nos alertas que já existem, sem enviar nada';

    public function handle(AlertReviewer $reviewer): int
    {
        if (! $reviewer->enabled()) {
            $this->error('O parecer da IA está desligado no admin ou o hermes não está configurado.');

            return self::FAILURE;
        }

        $matches = AlertMatch::query()
            ->where(fn ($q) => $q->whereNull('suppressed_reason')->orWhere('suppressed_reason', 'ai_low_relevance'))
            ->where(fn ($q) => $q->whereNull('delivery_note')->orWhere('delivery_note', '!=', 'ai_review'))
            ->when(! $this->option('again'), fn ($q) => $q->whereNull('ai_reviewed_at'))
            ->when($this->option('profile'), fn ($q, $id) => $q->where('profile_id', (int) $id))
            ->with(['profile', 'case'])
            ->oldest('matched_at')
            ->get()
            // Cita a empresa: nunca vai para a IA (o nome do cliente não sai daqui).
            ->reject(fn (AlertMatch $match) => $match->hasBrandReason() || ! $match->case);

        $cut = $reviewer->threshold();
        $tally = ['enviaria' => 0, 'seguraria' => 0, 'falhou' => 0];

        foreach ($matches as $match) {
            try {
                ['relevance' => $relevance, 'reason' => $reason] = retry(2, fn () => $reviewer->review($match));
            } catch (Throwable $e) {
                $tally['falhou']++;
                $this->warn("#{$match->id} sem parecer: {$e->getMessage()}");

                continue;
            }

            $send = $relevance > $cut;
            $tally[$send ? 'enviaria' : 'seguraria']++;
            $this->line(sprintf('#%d  %3d%%  %-9s  %s', $match->id, $relevance, $send ? 'enviaria' : 'seguraria', mb_substr($match->case->title_pt ?: $match->case->title, 0, 90)));

            if ($this->option('dry-run')) {
                continue;
            }

            $match->forceFill([
                'ai_relevance' => $relevance,
                'ai_reason' => $reason ?: null,
                'ai_reviewed_at' => now(),
                'suppressed_reason' => $send ? null : 'ai_low_relevance',
            ] + (! $send && $match->delivery_status === 'held'
                // Segurado pelo limite ou pelo silêncio: não pode sair na liberação.
                ? ['delivery_status' => 'skipped', 'release_at' => null]
                : []))->save();
        }

        $this->info(sprintf('%d alertas: %d enviaria, %d seguraria, %d sem parecer%s.',
            $matches->count(), $tally['enviaria'], $tally['seguraria'], $tally['falhou'], $this->option('dry-run') ? ' (nada gravado)' : ''));

        return self::SUCCESS;
    }
}
