<?php

namespace App\Services\Alerts;

use App\Exceptions\ActionRefused;
use App\Models\AlertMatch;
use App\Services\Links\SignedLinkUses;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * A avaliação do cliente num alerta ("Relevante", "Não é relevante" e o motivo
 * opcional), pelo painel ou pelo link assinado da mensagem. A IA do parecer
 * usa essas avaliações para decidir os próximos alertas.
 *
 * Pelo link, uma vez só: o primeiro voto vale e, a partir dele, os dois links
 * do alerta só mostram a resposta já registrada; o motivo da página também sai
 * uma vez. Mudar a avaliação continua livre no painel, com login.
 */
class AlertFeedback
{
    /** Resposta quando o motivo parece uma instrução para a IA. */
    public const FLAGGED_NOTE = 'Avaliação salva. O motivo ficou guardado, mas não vai para a IA porque parece uma instrução para ela. Escreva só por que a notícia interessa ou não.';

    public const LABELS = ['relevant' => 'relevante', 'irrelevant' => 'não relevante'];

    public function __construct(private SignedLinkUses $uses) {}

    /** Grava o voto e o motivo. Devolve true se o motivo parece instrução para a IA (fica guardado, mas fora do modelo). */
    public function rate(AlertMatch $match, string $feedback, ?string $note, bool $unaffected = false): bool
    {
        if (FeedbackNote::tooLong($note)) {
            throw new ActionRefused('O motivo pode ter até '.FeedbackNote::MAX.' caracteres.');
        }

        $note = FeedbackNote::clean($note);
        $flagged = FeedbackNote::looksLikeInjection($note);

        if ($flagged) {
            Log::notice('Motivo de avaliação com cara de instrução para a IA; não vai para o modelo', ['match' => $match->id]);
        }

        $match->forceFill([
            'feedback' => $feedback,
            'feedback_at' => now(),
            'feedback_note' => $note,
            'feedback_note_flagged' => $flagged,
            // "Não nos atinge" só existe junto com "Relevante".
            'feedback_unaffected' => $feedback === 'relevant' && $unaffected,
        ])->save();

        return $flagged;
    }

    /**
     * Voto pelo link da mensagem (Slack, Discord, Telegram), sem motivo. Só o
     * primeiro: false se o alerta já tinha voto (pelo link ou pelo painel). A
     * trava na linha segura dois cliques ao mesmo tempo.
     */
    public function vote(AlertMatch $match, string $feedback): bool
    {
        $voted = DB::transaction(function () use ($match, $feedback) {
            $locked = AlertMatch::query()->lockForUpdate()->findOrFail($match->id);

            return $locked->feedback === null
                && $locked->forceFill(['feedback' => $feedback, 'feedback_at' => now()])->save();
        });

        $match->refresh();

        return $voted;
    }

    /**
     * O motivo da página do link, uma vez por alerta. Não troca o voto já
     * registrado (isso é no painel); devolve se o motivo parece instrução para a IA.
     */
    public function rateOnce(AlertMatch $match, string $feedback, ?string $note, bool $unaffected = false): bool
    {
        $flagged = DB::transaction(function () use ($match, $feedback, $note, $unaffected) {
            $locked = AlertMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->feedback !== null && $locked->feedback !== $feedback) {
                throw new ActionRefused('A resposta já está registrada como “'.self::LABELS[$locked->feedback].'”. Para trocá-la, entre no Security Monitor, na aba Notícias.');
            }
            if (! $this->uses->claim($locked, SignedLinkUses::NOTE)) {
                throw new ActionRefused('O motivo deste alerta já foi registrado por este link. Para mudá-lo, entre no Security Monitor, na aba Notícias.');
            }

            return $this->rate($locked, $feedback, $note, $unaffected);
        });

        $match->refresh();

        return $flagged;
    }

    /** A página que o link assinado abre: o voto registrado e o formulário do motivo. */
    public function page(AlertMatch $match, ?string $status = null, ?string $error = null, bool $already = false): array
    {
        return [
            'match' => $match->load('case'),
            // Clicou de novo (no mesmo link ou no oposto): só a resposta que já estava registrada.
            'already' => $already,
            'noteOpen' => ! $this->uses->used($match, SignedLinkUses::NOTE),
            // O formulário do motivo também é assinado: vale sem login, só para este alerta.
            'noteUrl' => URL::temporarySignedRoute('alerts.feedback.signed.note', now()->addDays(30), ['match' => $match->id]),
            'status' => $status,
            'error' => $error,
        ];
    }
}
