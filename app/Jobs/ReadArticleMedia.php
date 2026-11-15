<?php

namespace App\Jobs;

use App\Models\Article;
use App\Services\Alerts\Discovery\SafeFetcher;
use App\Services\Crawler\ManualText;
use App\Services\Enrichment\ArticleMediaReader;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Prints e imagens que o admin mandou para um artigo: baixa as imagens do HTML
 * colado (pelo SafeFetcher: só IP público, redirect checado), manda tudo para
 * a IA ler e grava o texto resultante, com a descrição das figuras que fazem
 * parte do post. Depois a matéria é refeita como no texto colado.
 */
class ReadArticleMedia implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    /** Por imagem baixada do HTML. */
    public const MAX_IMAGE_BYTES = 3_000_000;

    /** Prints + figuras: o hermes aceita até 64 MB por pedido, já em base64 (+33%). */
    public const MAX_TOTAL_BYTES = 40_000_000;

    public function __construct(public int $articleId)
    {
        $this->onQueue('enrich');
    }

    public function uniqueId(): string
    {
        return (string) $this->articleId;
    }

    public function handle(ArticleMediaReader $reader, SafeFetcher $fetcher): void
    {
        $article = Article::query()->find($this->articleId);
        $media = $article?->manual_media;

        if (! $article || ($media['state'] ?? null) !== 'processing') {
            return;
        }

        // Desligada depois que o admin enviou: a tela mostra o motivo, e o texto colado pode ser reenviado sozinho.
        if (! ArticleMediaReader::enabled()) {
            $this->fail($article, $media, 'a leitura de prints e imagens foi desligada em Prompts');

            return;
        }

        $disk = Storage::disk('local');
        $budget = self::MAX_TOTAL_BYTES;
        $prints = [];

        foreach ($media['prints'] ?? [] as $path) {
            $data = $disk->get($path);

            if ($data !== null) {
                $prints[] = ['mime' => $disk->mimeType($path) ?: 'image/png', 'data' => $data];
                $budget -= strlen($data);
            }
        }

        $figures = [];
        $fetcher->startScan(max(1, count($media['images'] ?? []) * 4));

        foreach ($media['images'] ?? [] as $i => $image) {
            [$data, $mime, $error] = $this->download($fetcher, $image['url'], $budget);

            if ($error !== null) {
                $media['images'][$i]['error'] = $error;

                continue;
            }

            $path = $media['dir'].'/figura-'.sprintf('%02d', $i + 1).'.'.(explode('/', $mime)[1] ?? 'img');
            $disk->put($path, $data);
            $media['images'][$i]['path'] = $path;
            $budget -= strlen($data);
            $figures[] = ['mime' => $mime, 'data' => $data, 'url' => $image['url']];
        }

        if ($prints === [] && $figures === []) {
            $this->fail($article, $media, 'nenhuma imagem pôde ser lida (veja o motivo de cada uma)');

            return;
        }

        try {
            $result = $reader->read($article, (string) ($media['text'] ?? ''), $prints, $figures);
        } catch (Throwable $e) {
            $this->fail($article, $media, mb_substr($e->getMessage(), 0, 300));

            return;
        }

        $content = self::compose($result);

        if (mb_strlen($content) < ManualText::MIN_CHARS) {
            $this->fail($article, $media, 'a IA não encontrou texto suficiente da matéria nos prints');

            return;
        }

        $article->forceFill(['manual_media' => [
            ...$media,
            'state' => 'done',
            'figures' => $result['figures'],
            'error' => null,
            'finished_at' => now()->toIso8601String(),
        ]])->save();

        ManualText::apply($article, $content);
    }

    /** Job morto por timeout ou erro inesperado: a tela não pode ficar "lendo" para sempre. */
    public function failed(Throwable $e): void
    {
        $article = Article::query()->find($this->articleId);

        if ($article && ($article->manual_media['state'] ?? null) === 'processing') {
            $this->fail($article, $article->manual_media, mb_substr($e->getMessage(), 0, 300));
        }
    }

    /**
     * Texto da matéria e, no fim, as figuras que fazem parte do post. O texto é
     * cortado antes das figuras para elas não sumirem no teto do prompt.
     *
     * @param  array{text: string, figures: list<array{source: string, ref: int, part_of_post: bool, description: string}>}  $result
     */
    public static function compose(array $result): string
    {
        $lines = [];

        foreach ($result['figures'] as $figure) {
            if ($figure['part_of_post'] && $figure['description'] !== '') {
                $where = $figure['source'] === 'print' ? "no print {$figure['ref']}" : "imagem {$figure['ref']} do HTML";
                $lines[] = "- Figura ({$where}): {$figure['description']}";
            }
        }

        $figures = $lines === [] ? '' : "\n\nFiguras da matéria, descritas pela IA:\n".implode("\n", $lines);
        $text = mb_substr(ManualText::clean($result['text']), 0, max(0, ManualText::maxChars() - mb_strlen($figures)));

        return trim($text.$figures);
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} bytes, mime e erro */
    private function download(SafeFetcher $fetcher, string $url, int $budget): array
    {
        $response = $fetcher->get($url, fn () => null, accept: 'image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8');

        if (! $response['ok']) {
            return [null, null, $response['error'] ?? 'não baixou'];
        }

        $mime = strtolower(trim(explode(';', $response['headers']['content-type'][0] ?? '')[0]));

        return match (true) {
            ! str_starts_with($mime, 'image/') || $mime === 'image/svg+xml' => [null, null, "não é imagem ({$mime})"],
            $response['truncated'] ?? false, $response['bytes'] > self::MAX_IMAGE_BYTES => [null, null, 'imagem acima de 3 MB'],
            $response['bytes'] > $budget => [null, null, 'passou do limite total de imagens'],
            $response['bytes'] === 0 => [null, null, 'imagem vazia'],
            default => [$response['body'], $mime, null],
        };
    }

    private function fail(Article $article, array $media, string $error): void
    {
        $article->forceFill([
            'manual_media' => [...$media, 'state' => 'failed', 'error' => $error, 'finished_at' => now()->toIso8601String()],
            'error' => 'Leitura dos prints/imagens falhou: '.$error,
        ])->save();
    }
}
