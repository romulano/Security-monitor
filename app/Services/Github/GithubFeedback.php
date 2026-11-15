<?php

namespace App\Services\Github;

use App\Exceptions\ActionRefused;
use App\Models\GithubFinding;
use App\Services\Alerts\AlertFeedback;
use App\Services\Alerts\FeedbackNote;
use App\Services\Links\SignedLinkUses;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * A avaliação do cliente num achado do GitHub, com as mesmas medidas dos
 * alertas de notícia: "Relevante", "Não é relevante", "mas não nos atinge" e
 * o motivo opcional (até 600 caracteres, com as limpezas do FeedbackNote).
 * O parecer de envio (etapa 2) usa essas avaliações nos próximos achados.
 * Pelo link, uma vez só, como nos alertas (AlertFeedback).
 */
class GithubFeedback
{
    public const FLAGGED_NOTE = 'Avaliação salva. O motivo ficou guardado, mas não vai para a IA porque parece uma instrução para ela. Escreva só por que o achado interessa ou não.';

    /** Ação do link da mensagem → voto. */
    public const ACTIONS = ['relevante' => 'relevant', 'nao-relevante' => 'irrelevant'];

    public function __construct(private SignedLinkUses $uses) {}

    /** Grava o voto e o motivo; devolve true se o motivo parece instrução para a IA (fica guardado, fora do modelo). */
    public function rate(GithubFinding $finding, string $feedback, ?string $note, bool $unaffected = false): bool
    {
        if (FeedbackNote::tooLong($note)) {
            throw new ActionRefused('O motivo pode ter até '.FeedbackNote::MAX.' caracteres.');
        }

        $note = FeedbackNote::clean($note);
        $flagged = FeedbackNote::looksLikeInjection($note);

        if ($flagged) {
            Log::notice('Motivo de avaliação do GitHub com cara de instrução para a IA; não vai para o modelo', ['finding' => $finding->id]);
        }

        $finding->forceFill([
            'feedback' => $feedback,
            'feedback_at' => now(),
            'feedback_note' => $note,
            'feedback_note_flagged' => $flagged,
            'feedback_unaffected' => $feedback === 'relevant' && $unaffected,
        ])->save();

        return $flagged;
    }

    /** Voto pelo link da mensagem, sem motivo. Só o primeiro: false se o achado já tinha voto (pelo link ou pelo painel). */
    public function vote(GithubFinding $finding, string $action): bool
    {
        $voted = DB::transaction(function () use ($finding, $action) {
            $locked = GithubFinding::query()->lockForUpdate()->findOrFail($finding->id);

            return $locked->feedback === null
                && $locked->forceFill(['feedback' => self::ACTIONS[$action], 'feedback_at' => now()])->save();
        });

        $finding->refresh();

        return $voted;
    }

    /** O motivo da página do link, uma vez por achado, sem trocar o voto já registrado. */
    public function rateOnce(GithubFinding $finding, string $feedback, ?string $note, bool $unaffected = false): bool
    {
        $flagged = DB::transaction(function () use ($finding, $feedback, $note, $unaffected) {
            $locked = GithubFinding::query()->lockForUpdate()->findOrFail($finding->id);

            if ($locked->feedback !== null && $locked->feedback !== $feedback) {
                throw new ActionRefused('A resposta já está registrada como “'.AlertFeedback::LABELS[$locked->feedback].'”. Para trocá-la, entre no Security Monitor, na página do GitHub.');
            }
            if (! $this->uses->claim($locked, SignedLinkUses::NOTE)) {
                throw new ActionRefused('O motivo deste achado já foi registrado por este link. Para mudá-lo, entre no Security Monitor, na página do GitHub.');
            }

            return $this->rate($locked, $feedback, $note, $unaffected);
        });

        $finding->refresh();

        return $flagged;
    }

    /** A página que o link assinado abre: o voto registrado e o formulário (assinado) do motivo. */
    public function page(GithubFinding $finding, ?string $status = null, ?string $error = null, bool $already = false): array
    {
        return [
            'finding' => $finding->refresh(),
            'already' => $already,
            'noteOpen' => ! $this->uses->used($finding, SignedLinkUses::NOTE),
            'noteUrl' => URL::temporarySignedRoute('github.feedback.signed.note', now()->addDays(30), ['finding' => $finding->id]),
            'status' => $status,
            'error' => $error,
        ];
    }
}
