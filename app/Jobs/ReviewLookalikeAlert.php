<?php

namespace App\Jobs;

use App\Models\LookalikeAlert;
use App\Services\Lookalike\AlertPhoto;
use App\Services\Lookalike\BrandContext;
use App\Services\Lookalike\LookalikeAlertRouter;
use App\Services\Lookalike\LookalikeReviewer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Parecer da IA sobre um aviso do Radar antes do envio, como nos alertas de
 * notícia: acima do corte o aviso segue para os canais; no corte ou abaixo
 * fica "segurado pela IA" (o cliente vê no Radar e pode dizer que é golpe,
 * o que ensina a IA). Sem IA ou com ela fora do ar, o aviso sai assim mesmo.
 *
 * Fila `judge`, como o juiz dos alertas: as vagas do hermes são dela.
 */
class ReviewLookalikeAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 700;

    public function __construct(public int $alertId)
    {
        $this->onQueue('judge');
    }

    public function handle(LookalikeReviewer $reviewer, LookalikeAlertRouter $router): void
    {
        $alert = LookalikeAlert::query()->with(['lookalike.watch.domain', 'lookalike.watch.user.notificationChannels', 'lookalike.lastCapture', 'capture'])->find($this->alertId);

        // Job repetido: o que já foi julgado não volta.
        if (! $alert || $alert->status !== 'ai_review') {
            return;
        }

        if (! $reviewer->enabled()) {
            $router->route($alert);

            return;
        }

        try {
            // Uma resposta ruim isolada acontece; a segunda costuma vir certa.
            ['threat' => $threat, 'reason' => $reason, 'device' => $device] = retry(2, fn () => $reviewer->review($alert));
        } catch (Throwable $e) {
            // Sem parecer, o aviso sai: perder um golpe custa mais que mandar um a mais.
            Log::warning('Parecer da IA do Radar indisponível; aviso enviado sem parecer', ['alert' => $alert->id, 'error' => $e->getMessage()]);
            $alert->forceFill(['ai_reason' => mb_substr('Sem parecer da IA ('.$e->getMessage().'); enviado assim mesmo.', 0, 500), 'ai_reviewed_at' => now()])->save();
            $router->route($alert);

            return;
        }

        $lookalike = $alert->lookalike;
        $capture = $alert->capture ?? $lookalike->lastCapture;
        $photo = $capture ? AlertPhoto::pick($capture, BrandContext::for($lookalike->watch), $device) : null;

        $alert->forceFill([
            'ai_score' => $threat,
            'ai_reason' => $reason ?: null,
            'ai_device' => $device,
            'ai_reviewed_at' => now(),
            'photo' => $photo ?? $alert->photo,
        ])->save();

        $lookalike->forceFill([
            'ai_score' => $threat,
            'ai_reason' => $reason ?: null,
            'ai_device' => $device,
            'ai_reviewed_at' => now(),
            'ai_capture_id' => $capture?->id,
        ])->save();

        if ($threat > $reviewer->threshold()) {
            $router->route($alert);
        } else {
            $alert->forceFill(['status' => 'held', 'note' => 'ai_low_threat'])->save();
        }
    }
}
