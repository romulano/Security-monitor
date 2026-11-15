<?php

namespace App\Support;

/**
 * Texto corrido escrito pela IA para a página: sem markdown, sem bloco de
 * código e sem endereço clicável, cortado no teto.
 */
class PlainText
{
    public static function from(mixed $text, int $max): string
    {
        if (! is_string($text)) {
            return '';
        }

        $text = preg_replace('/```.*?(```|$)/s', ' ', $text);
        $text = preg_replace('#\b(?:https?|ftp)://\S+#i', '', $text);
        $text = preg_replace('/(^|\s)#{1,6}\s+/', '$1', str_replace(['`', '**', '__'], '', $text));
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }
}
