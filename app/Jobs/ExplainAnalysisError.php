<?php

namespace App\Jobs;

use App\Models\ErrorExplanation;
use App\Services\Enrichment\ErrorExplainer;
use App\Services\Enrichment\ErrorReasons;
use App\Services\Hermes\HermesLimitReached;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pede ao hermes o motivo claro de um erro de análise que o mapa fixo não conhece
 * (ErrorReasons) e guarda por assinatura. Falhou, a tela continua com o texto
 * técnico e "motivo ainda não explicado".
 *
 * Fila `merge`, como a fusão: o worker de análise só a atende com a `enrich`
 * vazia, então a explicação usa as vagas do hermes sem atrasar as notícias. No
 * limite de uso da conta, volta à fila até a hora que o hermes dá, por um dia.
 */
class ExplainAnalysisError implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public function __construct(
        public string $signatureHash,
        public string $error,
        public string $driver,
        public string $stage,
        public int $articleChars,
    ) {
        $this->onQueue('merge');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(ErrorReasons $reasons, ErrorExplainer $explainer): void
    {
        $row = ErrorExplanation::query()->where('signature_hash', $this->signatureHash)->first();

        // Desligada na aba Prompts depois de entrar na fila: fica para depois.
        if (! $row || $row->status === 'done' || ! $reasons->enabled()) {
            return;
        }

        try {
            $text = $explainer->explain($this->error, $this->driver, $this->stage, $this->articleChars);
        } catch (HermesLimitReached $e) {
            $this->release(max(60, (int) now()->diffInSeconds($e->resetsAt->copy()->addMinutes(5))));

            return;
        } catch (Throwable $e) {
            Log::warning('Explicação do erro de análise falhou', ['assinatura' => $row->signature, 'error' => $e->getMessage()]);
            $row->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)])->save();

            return;
        }

        $row->forceFill(['status' => 'done', 'explanation' => $text, 'error' => null])->save();
    }
}
