<?php

namespace App\Services\Github;

use App\Models\GithubFinding;
use App\Services\Hermes\Hermes;
use App\Support\Prompts;
use App\Support\TagCode;

/**
 * Etapa 1 do monitoramento do GitHub: o classificador próprio deste
 * monitoramento (não é o juiz dos alertas de notícia). Lê o achado com os
 * segredos mascarados e diz o nível (critical, high, medium, low ou none =
 * não é achado), a certeza de 0 a 100 de ser um achado real ligado ao cliente
 * e o motivo. Só o que é achado, no nível escolhido pelo cliente, segue para
 * o parecer de envio (GithubJudge).
 */
class GithubTriage
{
    public const LEVELS = ['critical', 'high', 'medium', 'low', 'none'];

    /** Abaixo desta certeza não é achado, diga a IA o nível que disser. */
    public const MIN_CONFIDENCE = 40;

    public function enabled(): bool
    {
        // Desligado no .env ou na aba Prompts, vale o nível pelas regras.
        return (bool) config('secmon.github.ai_triage_enabled') && Hermes::configured() && Prompts::stageOn('github-classificador');
    }

    /** @return array{level: string, confidence: int, reason: string} */
    public function classify(GithubFinding $finding): array
    {
        $nonce = bin2hex(random_bytes(4));

        return self::parse(Hermes::json(self::system(), TagCode::header($nonce).$this->prompt($finding, $nonce), extra: Hermes::light()));
    }

    public function prompt(GithubFinding $finding, ?string $nonce = null): string
    {
        $nonce ??= bin2hex(random_bytes(4));
        $monitor = $finding->monitor;

        return implode("\n", [
            ...GithubContext::client($monitor, $nonce),
            '',
            ...GithubContext::finding($finding, $nonce),
        ]);
    }

    /** @return array{level: string, confidence: int, reason: string} */
    public static function parse(array $data): array
    {
        $level = mb_strtolower(trim((string) ($data['level'] ?? '')));

        if (! in_array($level, self::LEVELS, true) || ! is_numeric($data['confidence'] ?? null)) {
            throw new \RuntimeException('a IA não devolveu uma classificação utilizável');
        }

        $confidence = max(0, min(100, (int) round((float) $data['confidence'])));

        return [
            'level' => $confidence < self::MIN_CONFIDENCE ? 'none' : $level,
            'confidence' => $confidence,
            'reason' => Hermes::sentence($data['reason'] ?? ''),
        ];
    }

    /**
     * As diretrizes são editáveis no admin (Prompts › Monitoramento do GitHub › Classificador).
     * Igual em toda chamada (para o cache): as etiquetas vão com o marcador, o código vai na mensagem.
     */
    public static function system(): string
    {
        return rtrim(Prompts::text('github.triage'))."\n".self::reply(TagCode::CODE)."\n".TagCode::rule();
    }

    /** Parte fixa: as etiquetas do texto de terceiros e o formato que o parse() lê. */
    public static function reply(string $nonce): string
    {
        return GithubContext::untrusted($nonce, ['code', 'page', 'client'])."\n"
            .'- If your confidence is below '.self::MIN_CONFIDENCE.", the level must be none.\n\n"
            .'Reply with a single JSON object {"level": "critical|high|medium|low|none", "confidence": <integer 0-100>, '
            .'"reason": "<one sentence, at most 200 characters, in Portuguese (Brazil), telling the client what was found and why this level>"} '
            .'and nothing else — no prose, no code fence.';
    }
}
