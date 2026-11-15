<?php

namespace App\Services\Alerts;

use Illuminate\Support\Str;

/**
 * O motivo que o cliente escreve ao avaliar um alerta ("não usamos SUSE, só
 * Ubuntu"). Vai para a IA do parecer, então é tratado como entrada hostil:
 *
 * - ao salvar: só texto, numa linha, até 600 caracteres (um parágrafo curto);
 * - texto com cara de instrução para a IA ("ignore as regras", "dê 100%") fica
 *   guardado, mas nunca vai para o modelo (o voto continua valendo);
 * - antes do prompt: sem símbolos de marcação (< > { } [ ] # `), sem links, e-mails
 *   e números de telefone ou documento, e sem o nome da empresa, marcas, domínio e nome do
 *   cliente (a política de privacidade promete que a IA não o identifica).
 */
final class FeedbackNote
{
    /**
     * Um parágrafo curto: cabe uma explicação técnica como "as versões travadas nos
     * lockfiles são anteriores ao ataque (04/08/2026)…" (515 caracteres), com folga.
     */
    public const MAX = 600;

    /** Testados sobre o texto minúsculo e sem acento. */
    private const INJECTION = [
        // Trocar a tarefa ou as regras.
        '/\b(ignore|ignora\w*|desconsider\w*|esquec\w*|esqueca\w*|forget|disregard|override|sobrescrev\w*|bypass)\b.{0,40}\b(instruc\w*|instruct\w*|regras?|rules?|prompt|orientac\w*|anteriores?|previous|above|acima)\b/',
        '/\b(system|developer)\s*(prompt|message|mode)\b/',
        '/\b(prompt|instrucoes|mensagem|regras)\s+(do|de)\s+sistema\b/',
        '/\b(you are|you\'re|voce e|vc e|voce|vc)\s+(now|agora)\b/',
        '/\b(act|aja|atue|finja|pretend|roleplay)\b.{0,20}\b(as|como|que|to be)\b/',
        '/\b(jailbreak|do anything now|god\s*mode|modo\s+(desenvolvedor|dev|deus|irrestrito))\b|\bdan\b/',
        // Ditar a nota ou o formato da resposta.
        '/\b(coloque|ponha|retorne|responda|devolva|atribua|defina|give|return|output|assign|respond|rate)\b.{0,30}(\b\d{1,3}\s*(%|por ?cento|percent)|\bnota\b|\bscore\b|\bjson\b|\brelevance\b)/',
        '/"?\b(relevance|reason)\b"?\s*:/',
        // Marcadores de papel e de fim de bloco.
        '/<\||\|>|\[\/?(inst|system)\]|\b(system|assistant)\s*:/',
    ];

    /** Para guardar: uma linha só, sem caracteres de controle, até o limite. */
    public static function clean(?string $raw): ?string
    {
        $text = self::normalize($raw);

        return $text === '' ? null : mb_substr($text, 0, self::MAX);
    }

    /** Passa do limite? (contado já em uma linha, como vai ser guardado) */
    public static function tooLong(?string $raw): bool
    {
        return mb_strlen(self::normalize($raw)) > self::MAX;
    }

    private static function normalize(?string $raw): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[\p{C}]+/u', ' ', (string) $raw)));
    }

    /** Parece uma tentativa de instruir ou manipular a IA? */
    public static function looksLikeInjection(?string $note): bool
    {
        if ($note === null || $note === '') {
            return false;
        }

        $folded = mb_strtolower(Str::ascii($note));

        foreach (self::INJECTION as $pattern) {
            if (preg_match($pattern, $folded) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Versão que pode ir para o modelo, ou null se não deve ir.
     *
     * @param  list<string>  $redact  nome da empresa, marcas, domínios, nome e e-mail do cliente
     */
    public static function forPrompt(?string $note, array $redact = []): ?string
    {
        $text = self::clean($note);

        if ($text === null || self::looksLikeInjection($text)) {
            return null;
        }

        $text = preg_replace('~\bhttps?://\S+|\bwww\.\S+~iu', '(link)', $text);
        $text = preg_replace('/[\w.+\-]+@[\w\-]+(\.[\w\-]+)+/u', '(email)', $text);
        $text = preg_replace('/<\/?[a-z][^>]*>/iu', ' ', $text);
        // Telefone, CPF, CNPJ (10 dígitos ou mais); datas e versões ficam.
        $text = preg_replace_callback('/\d[\d .\-\/]{7,}\d/u', fn ($m) => preg_match_all('/\d/', $m[0]) >= 10 ? '(número)' : $m[0], $text);
        $text = self::redact($text, $redact);

        // Só letras, números e pontuação comum (com _ e @ de arquivos e pacotes):
        // nada de tags, chaves, colchetes ou markdown.
        $text = preg_replace('/[^\p{L}\p{N}\s.,;:!?()\'"%\/+_@\-]/u', ' ', $text);
        // Espaço que sobrou antes de pontuação final ("SUSE ,"), mas não antes de ".claude".
        $text = trim(preg_replace(['/\s+/u', '/\s+([,.;:!?])(?=\s|$)/u'], [' ', '$1'], $text));

        return $text === '' ? null : mb_substr($text, 0, self::MAX);
    }

    /**
     * Troca o que identifica o cliente por "(omitido)". Do maior para o menor,
     * para "Acme Pagamentos" sair inteiro antes de "Acme".
     *
     * @param  list<string>  $terms
     */
    public static function redact(string $text, array $terms): string
    {
        $terms = array_unique(array_filter(array_map('trim', $terms), fn ($t) => mb_strlen($t) >= 3));
        usort($terms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($terms as $term) {
            $text = preg_replace('/'.preg_quote($term, '/').'/iu', '(omitido)', $text);
        }

        return $text;
    }
}
