<?php

namespace App\Services\Crawler;

use App\Jobs\EnrichArticle;
use App\Jobs\RebuildCase;
use App\Models\Article;

/**
 * Texto completo fornecido pelo admin (colado, tirado de HTML ou lido de
 * prints pela IA) para um artigo em que o site só deixou ler o resumo do feed.
 */
class ManualText
{
    public const MIN_CHARS = 200;

    /** Quebras de linha uniformes e no máximo uma linha em branco entre parágrafos. */
    public static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /** HTML colado: tags de bloco o bastante para não ser texto com um "<" solto (IOC, código). */
    public static function looksLikeHtml(string $raw): bool
    {
        return preg_match_all('#<(?:p|div|article|section|main|h[1-6]|img|br|span|li|ul|figure|html|body|a)\b[^>]*>#i', $raw) >= 3;
    }

    public static function maxChars(): int
    {
        return (int) config('secmon.crawler.max_content_chars', 24000);
    }

    /**
     * Grava o texto como `extraction = manual` e refaz a matéria no lugar (mesmo
     * link); artigo que nunca virou matéria volta para a fila de análise.
     * Devolve a mensagem para o admin.
     */
    public static function apply(Article $article, string $text): string
    {
        $text = mb_substr($text, 0, self::maxChars());

        $article->forceFill([
            'content' => $text,
            'content_chars' => mb_strlen($text),
            'extraction' => 'manual',
            'error' => null,
        ])->save();

        if ($article->newsCase) {
            RebuildCase::start($article->newsCase->id, $article->id);

            return 'Texto salvo. A matéria está sendo refeita com o texto completo (leva alguns minutos); o link continua o mesmo.';
        }

        $article->forceFill(['status' => 'pending', 'attempts' => 0])->save();
        EnrichArticle::dispatch($article->id, 'refazer-materia')->onQueue('enrich');

        return 'Texto salvo. O artigo voltou para a fila de análise.';
    }
}
