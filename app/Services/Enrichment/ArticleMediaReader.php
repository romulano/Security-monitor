<?php

namespace App\Services\Enrichment;

use App\Models\Article;
use App\Services\Hermes\Hermes;
use App\Support\Prompts;
use RuntimeException;

/**
 * Lê, pelo hermes (o Claude Code da máquina, que aceita imagens), os prints de
 * uma matéria e as imagens encontradas no HTML dela: transcreve o texto dos
 * prints e decide, imagem por imagem, se ela faz parte do post — figura,
 * print de código/IOC, gráfico — ou é logo, propaganda, foto de autor.
 */
class ArticleMediaReader
{
    /** O CLI leva mais tempo com imagem do que com texto puro. */
    private const TIMEOUT = 600;

    /** Parte fixa: o parse() depende deste formato. */
    public const REPLY = 'Reply with a single JSON object and nothing else — no prose, no code fence.';

    public const SHAPE = <<<'SHAPE'
    JSON shape:
    {"text": "<full article text>",
     "figures": [{"source": "figure" or "print", "ref": <FIGURE number, or the PRINT number where it appears>, "part_of_post": true or false, "reason": "<short reason>", "description": "<what it shows; empty when not part of the post>"}]}
    SHAPE;

    /**
     * @param  list<array{mime: string, data: string}>  $prints  na ordem de leitura
     * @param  list<array{mime: string, data: string, url: string}>  $figures
     * @return array{text: string, figures: list<array{source: string, ref: int, part_of_post: bool, reason: string, description: string}>}
     */
    public function read(Article $article, string $text, array $prints, array $figures): array
    {
        if (! Hermes::configured()) {
            throw new RuntimeException('a leitura de imagens usa o hermes, que não está configurado');
        }

        $content = [['type' => 'text', 'text' => self::prompt($article, $text, count($prints), count($figures))]];

        foreach ($prints as $i => $print) {
            $content[] = ['type' => 'text', 'text' => 'PRINT '.($i + 1)];
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => self::dataUrl($print)]];
        }

        foreach ($figures as $i => $figure) {
            $content[] = ['type' => 'text', 'text' => 'FIGURE '.($i + 1).' — '.$figure['url']];
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => self::dataUrl($figure)]];
        }

        return self::parse(Hermes::chat(self::system(), $content, self::TIMEOUT, margin: 30));
    }

    /** @return array{text: string, figures: list<array{source: string, ref: int, part_of_post: bool, reason: string, description: string}>} */
    public static function parse(string $content): array
    {
        $data = Hermes::object($content);

        if ($data === null) {
            throw new RuntimeException('a IA não devolveu JSON utilizável na leitura das imagens');
        }

        $figures = [];

        foreach (is_array($data['figures'] ?? null) ? $data['figures'] : [] as $figure) {
            if (! is_array($figure)) {
                continue;
            }

            $figures[] = [
                'source' => ($figure['source'] ?? null) === 'print' ? 'print' : 'figure',
                'ref' => (int) ($figure['ref'] ?? 0),
                'part_of_post' => filter_var($figure['part_of_post'] ?? false, FILTER_VALIDATE_BOOL),
                'reason' => trim((string) ($figure['reason'] ?? '')),
                'description' => trim((string) ($figure['description'] ?? '')),
            ];
        }

        return ['text' => trim((string) ($data['text'] ?? '')), 'figures' => $figures];
    }

    /** Desligada na aba Prompts, os prints e as imagens são ignorados: vale só o texto colado. */
    public static function enabled(): bool
    {
        return Prompts::stageOn('midia');
    }

    /** O papel e as tarefas são editáveis no admin (Prompts › Leitura de prints e imagens). */
    public static function system(): string
    {
        return Prompts::text('media.system')."\n\n".self::REPLY;
    }

    /** As imagens vão logo depois deste texto, cada uma com o rótulo PRINT n ou FIGURE n. */
    public static function prompt(Article $article, string $text, int $prints, int $figures): string
    {
        $title = $article->title ?: $article->original_title;

        return implode("\n\n", [
            "Article: \"{$title}\" — {$article->source_host}\nURL: {$article->url}\nYou received {$prints} print(s) and {$figures} figure(s).",
            "Text already available (pasted by the admin; may be empty or partial):\n<text>\n{$text}\n</text>",
            Prompts::text('media.tasks'),
            self::SHAPE,
        ]);
    }

    /** @param  array{mime: string, data: string}  $image */
    private static function dataUrl(array $image): string
    {
        return 'data:'.$image['mime'].';base64,'.base64_encode($image['data']);
    }
}
