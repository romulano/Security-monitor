<?php

namespace App\Services\Crawler;

use App\Jobs\ReadArticleMedia;
use App\Models\Article;
use App\Services\Enrichment\ArticleMediaReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Texto completo fornecido pelo admin para artigos em que o site da fonte só
 * deixou o crawler ler o resumo do feed (WAF, página montada por JavaScript).
 * Aceita o texto, o HTML da página e prints: com imagens no meio, a IA lê na
 * fila (ReadArticleMedia); sem imagens, o texto vale na hora.
 */
class ManualTextIntake
{
    public const MAX_PRINTS = 10;

    /** Por print, em KB (validação do Laravel). */
    public const MAX_PRINT_KB = 5120;

    public function __construct(private ArticleExtractor $extractor) {}

    /** O artigo com a matéria dele, para a tela do texto. */
    public function forEdit(Article $article): Article
    {
        return $article->load('newsCase:id,uuid,title');
    }

    /**
     * Sem imagem nenhuma, grava o texto na hora (`queued` false, com a
     * mensagem para o admin). Com prints ou imagens no HTML, guarda tudo e a
     * IA lê na fila (`queued` true, com quantas imagens vai ler).
     *
     * @param  'auto'|'text'|'html'  $format
     * @param  list<UploadedFile>  $prints
     * @return array{queued: false, message: string}|array{queued: true, prints: int, images: int}
     */
    public function submit(Article $article, string $raw, string $format, array $prints): array
    {
        $isHtml = match ($format) {
            'html' => true,
            'text' => false,
            default => ManualText::looksLikeHtml($raw),
        };

        $text = $raw;
        $images = [];

        if ($isHtml && trim($raw) !== '') {
            $parsed = $this->extractor->fromHtml($raw, $article->url, withImages: true);
            $text = $parsed['text'];
            $images = $parsed['images'];
        }

        $text = ManualText::clean($text);

        // Leitura de prints desligada na aba Prompts: vale só o texto.
        $skipped = ! ArticleMediaReader::enabled() && ($prints !== [] || $images !== []);

        if ($skipped) {
            [$prints, $images] = [[], []];
        }

        // Sem imagem nenhuma, não há o que a IA ler antes: o texto vale na hora.
        if ($prints === [] && $images === []) {
            if (mb_strlen($text) < ManualText::MIN_CHARS) {
                throw ValidationException::withMessages(['content' => match (true) {
                    $skipped => 'A leitura de prints e imagens está desligada (Admin › Prompts). Cole o texto completo da matéria.',
                    $isHtml => 'Não achei o texto da matéria nesse HTML. Copie o HTML da página inteira (Ctrl+U no navegador) ou cole o texto.',
                    default => 'Cole o texto completo da matéria (pelo menos '.ManualText::MIN_CHARS.' caracteres).',
                }]);
            }

            $message = ManualText::apply($article, $text);

            return ['queued' => false, 'message' => $skipped ? $message.' A leitura de prints e imagens está desligada em Prompts: só o texto foi usado.' : $message];
        }

        $dir = 'article-media/'.$article->id.'/'.now()->format('Ymd-His');
        $paths = [];

        foreach (array_values($prints) as $i => $file) {
            $paths[] = $file->storeAs($dir, sprintf('print-%02d.%s', $i + 1, $file->extension()), 'local');
        }

        $article->forceFill([
            'manual_media' => [
                'state' => 'processing',
                'dir' => $dir,
                'text' => $text,
                'from_html' => $isHtml,
                'prints' => $paths,
                'images' => array_map(fn (string $url) => ['url' => $url, 'path' => null, 'error' => null], $images),
                'figures' => [],
                'error' => null,
                'requested_at' => now()->toIso8601String(),
            ],
            'error' => null,
        ])->save();

        ReadArticleMedia::dispatch($article->id);

        return ['queued' => true, 'prints' => count($paths), 'images' => count($images)];
    }
}
