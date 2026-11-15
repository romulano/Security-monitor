<?php

namespace App\Jobs;

use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Services\Alerts\AlertReviewer;
use App\Services\Alerts\AlertRouter;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Parecer da IA sobre os alertas de um cliente para um caso, antes do envio:
 * acima do corte, o alerta segue para o AlertRouter; no corte ou abaixo, fica
 * "segurado pela IA" (o cliente vê em Meus alertas e pode dizer que era
 * relevante, o que ensina a IA).
 *
 * Roda na fila `judge`, que o worker de análise atende antes da `enrich`: as
 * duas vagas do hermes são dele, e o alerta não espera a fila de análise.
 *
 * Um cliente por vez: duas fontes do mesmo caso costumam chegar na mesma
 * coleta e, julgadas em paralelo, uma não veria a outra como já enviada.
 */
class ReviewAlertMatches implements ShouldQueue
{
    use Queueable;

    /** Erro de verdade falha na segunda vez; esperar a vez do cliente não conta. */
    public int $maxExceptions = 2;

    public int $timeout = 300;

    /** @param  list<int>  $matchIds  alertas do mesmo cliente para o mesmo caso */
    public function __construct(public array $matchIds)
    {
        $this->onQueue('judge');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        $user = AlertProfile::query()
            ->whereIn('id', AlertMatch::query()->whereIn('id', $this->matchIds)->select('profile_id'))
            ->value('user_id');

        return [(new WithoutOverlapping('user-'.$user))->releaseAfter(10)->expireAfter($this->timeout)];
    }

    /** Com a fila de um cliente cheia, a vez dele pode demorar; o alerta não se perde. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle(AlertReviewer $reviewer, AlertRouter $router): void
    {
        $matches = AlertMatch::query()
            ->whereIn('id', $this->matchIds)
            ->where('delivery_note', 'ai_review') // job repetido: o que já foi julgado não volta
            ->with(['profile.user.notificationChannels', 'case'])
            ->get();

        // A matéria que saiu do feed público enquanto esperava a vez nem chega à IA.
        $approved = $router->withoutHidden($matches)->filter(fn (AlertMatch $match) => $this->judge($reviewer, $match));

        if ($approved->isNotEmpty()) {
            $router->routeGroup($approved);
        }
    }

    private function judge(AlertReviewer $reviewer, AlertMatch $match): bool
    {
        try {
            // Uma resposta ruim isolada acontece (visto em prod); a segunda costuma vir certa.
            ['relevance' => $relevance, 'reason' => $reason] = retry(2, fn () => $reviewer->review($match));
        } catch (Throwable $e) {
            // Sem parecer, o alerta sai: perder um aviso de segurança custa mais
            // que mandar um a mais.
            Log::warning('Juiz de IA indisponível; alerta enviado sem parecer', ['match' => $match->id, 'error' => $e->getMessage()]);
            $match->forceFill([
                'ai_reason' => mb_substr('Sem parecer da IA ('.$e->getMessage().'); enviado assim mesmo.', 0, 500),
                'ai_reviewed_at' => now(),
                'delivery_note' => null,
            ])->save();

            return true;
        }

        $send = $relevance > $reviewer->threshold();

        $match->forceFill([
            'ai_relevance' => $relevance,
            'ai_reason' => $reason ?: null,
            'ai_reviewed_at' => now(),
            'delivery_note' => null,
        ] + ($send ? [] : ['suppressed_reason' => 'ai_low_relevance', 'delivery_status' => 'skipped']))->save();

        return $send;
    }
}
