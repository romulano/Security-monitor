<?php

namespace App\Services\Lookalike;

use App\Exceptions\ActionRefused;
use App\Models\LookalikeDomain;
use App\Services\Alerts\FeedbackNote;
use App\Services\Links\SignedLinkUses;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Avaliação do cliente sobre um domínio do Radar ("É golpe", "É nosso", "Não
 * é ameaça"), com motivo opcional: a IA usa nos próximos achados. Vem da tela
 * do Radar ou dos botões do aviso no Slack, Discord e Telegram (link assinado).
 * Pelos botões, uma vez só: a primeira resposta vale e, a partir dela, os três
 * botões só mostram a resposta registrada; o motivo da página também sai uma vez.
 * Se a resposta for desfeita (na tela do Radar, ou "Não é ameaça" que subiu de
 * nível), os botões voltam a valer.
 */
class LookalikeVerdicts
{
    /** Ação do formulário → resposta gravada (`desfazer` apaga a avaliação). */
    public const VERDICTS = ['nosso' => 'ours', 'seguro' => 'safe', 'golpe' => 'threat', 'desfazer' => null];

    public const FLAGGED_NOTE = 'Avaliação salva. O motivo ficou guardado, mas não vai para a IA porque parece uma instrução para ela. Escreva só por que o domínio é ou não uma ameaça.';

    /** Resposta gravada → como a página do link a chama. */
    public const LABELS = ['threat' => 'golpe', 'ours' => 'de vocês', 'safe' => 'não ameaça'];

    public function __construct(private RiskScorer $scorer, private SignedLinkUses $uses) {}

    /**
     * Grava a resposta (e o motivo) e recalcula o nível; devolve se o motivo
     * ficou de fora da IA por parecer uma instrução para ela.
     *
     * @param  string|false|null  $note  false: mantém o motivo que já existe (botão rápido, sem campo de motivo)
     */
    public function save(LookalikeDomain $lookalike, string $action, string|false|null $note): bool
    {
        if ($note !== false && FeedbackNote::tooLong($note)) {
            throw new ActionRefused('O motivo pode ter até '.FeedbackNote::MAX.' caracteres.');
        }

        $lookalike->loadMissing(['lastCapture', 'watch.domain']);
        $verdict = self::VERDICTS[$action];
        $changedVerdict = $lookalike->verdict !== $verdict;
        $lookalike->fill(['verdict' => $verdict, 'verdict_at' => $verdict ? now() : null]);

        if ($note !== false || ($changedVerdict && $verdict === null)) {
            $clean = $note === false ? null : FeedbackNote::clean($note);
            $lookalike->fill(['verdict_note' => $verdict ? $clean : null, 'verdict_note_flagged' => $verdict && FeedbackNote::looksLikeInjection($clean)]);
        } elseif ($changedVerdict) {
            // Trocou a resposta sem escrever: o motivo antigo era de outra resposta.
            $lookalike->fill(['verdict_note' => null, 'verdict_note_flagged' => false]);
        }

        $this->scorer->apply($lookalike, BrandContext::for($lookalike->watch));
        $lookalike->save();

        return (bool) $lookalike->verdict_note_flagged;
    }

    /** Botão do aviso, sem motivo. Só o primeiro: false se o domínio já tinha resposta (pelo botão ou pela tela). */
    public function saveOnce(LookalikeDomain $lookalike, string $action): bool
    {
        return DB::transaction(function () use ($lookalike, $action) {
            $locked = LookalikeDomain::query()->lockForUpdate()->findOrFail($lookalike->id);

            if ($locked->verdict !== null) {
                return false;
            }
            $this->save($locked, $action, false);

            return true;
        });
    }

    /** O motivo da página do link, uma vez por domínio, sem trocar a resposta já registrada. */
    public function saveNoteOnce(LookalikeDomain $lookalike, string $action, ?string $note): bool
    {
        return DB::transaction(function () use ($lookalike, $action, $note) {
            $locked = LookalikeDomain::query()->lockForUpdate()->findOrFail($lookalike->id);

            if ($locked->verdict !== null && $locked->verdict !== self::VERDICTS[$action]) {
                throw new ActionRefused('A resposta já está registrada como “'.self::LABELS[$locked->verdict].'”. Para trocá-la, entre no Security Monitor, no Radar.');
            }
            if (! $this->uses->claim($locked, SignedLinkUses::NOTE)) {
                throw new ActionRefused('O motivo deste domínio já foi registrado por este link. Para mudá-lo, entre no Security Monitor, no Radar.');
            }

            return $this->save($locked, $action, $note);
        });
    }

    /** A página que os botões do aviso abrem (sem login): a resposta salva e o formulário assinado do motivo. */
    public function signedPage(LookalikeDomain $lookalike, bool $already = false): array
    {
        return [
            'lookalike' => $lookalike->refresh(),
            'already' => $already,
            'noteOpen' => ! $this->uses->used($lookalike, SignedLinkUses::NOTE),
            'noteUrl' => URL::temporarySignedRoute('lookalikes.verdict.signed.note', now()->addDays(30), ['lookalike' => $lookalike->id]),
            'status' => null,
            'error' => null,
        ];
    }
}
