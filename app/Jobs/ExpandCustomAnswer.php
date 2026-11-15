<?php

namespace App\Jobs;

use App\Models\AlertProfile;
use App\Models\CustomAnswerTerm;
use App\Services\Alerts\CustomAnswerExpander;
use App\Services\Alerts\InterviewService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Gera os termos de busca de uma resposta escrita na entrevista e recompila
 * os perfis que a usam. Fila `alerts`: é uma chamada curta e o cliente acabou
 * de responder — não espera a fila de análise de notícias.
 */
class ExpandCustomAnswer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 150;

    public int $uniqueFor = 300;

    public function __construct(public int $answerId)
    {
        $this->onQueue('alerts');
    }

    public function uniqueId(): string
    {
        return (string) $this->answerId;
    }

    public function backoff(): array
    {
        return [60];
    }

    public function handle(CustomAnswerExpander $expander, InterviewService $interview): void
    {
        $answer = CustomAnswerTerm::query()->find($this->answerId);

        // O que o admin corrigiu à mão não é sobrescrito pela IA. Com a etapa
        // desligada, a resposta segue pendente e vale como termo do jeito que veio.
        if (! $answer || $answer->edited_by_admin || ! CustomAnswerExpander::enabled()) {
            return;
        }

        try {
            $result = $expander->expand($answer);
        } catch (Throwable $e) {
            $answer->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

            if ($this->attempts() < $this->tries) {
                $this->release(60);
            }

            return;
        }

        $answer->forceFill(['terms' => $result['terms'], 'codes' => $result['codes'], 'status' => 'ready', 'error' => null])->save();

        foreach (self::profilesUsing($answer) as $profile) {
            $interview->recompile($profile);
        }
    }

    /** @return iterable<AlertProfile> */
    public static function profilesUsing(CustomAnswerTerm $answer): iterable
    {
        return AlertProfile::query()->whereNotNull('custom_answers')->get()->filter(function (AlertProfile $profile) use ($answer) {
            foreach ((array) $profile->custom_answers as $key => $labels) {
                if (CustomAnswerTerm::kindFor($key) !== $answer->kind) {
                    continue;
                }

                foreach ((array) $labels as $label) {
                    if (CustomAnswerTerm::normalize((string) $label) === $answer->normalized) {
                        return true;
                    }
                }
            }

            return false;
        });
    }
}
