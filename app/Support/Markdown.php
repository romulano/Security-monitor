<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Os resumos vêm em Markdown do enriquecimento (e do acervo importado). O HTML
 * é gerado com `html_input: strip`: o texto vem de terceiros, então nada de
 * HTML bruto passa adiante.
 */
class Markdown
{
    private static ?MarkdownConverter $converter = null;

    public static function toHtml(?string $markdown): HtmlString
    {
        if ($markdown === null || trim($markdown) === '') {
            return new HtmlString('');
        }

        self::$converter ??= self::build();

        return new HtmlString((string) self::$converter->convert($markdown));
    }

    /** Texto puro, para meta description e prévias no feed. */
    public static function toText(?string $markdown, int $limit = 320): string
    {
        if ($markdown === null) {
            return '';
        }

        $text = preg_replace('/^#{1,6}\s+/m', '', $markdown) ?? '';
        $text = preg_replace('/[*_`>#\[\]]+/', '', $text) ?? '';
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;
    }

    private static function build(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new AutolinkExtension);

        return new MarkdownConverter($environment);
    }
}
