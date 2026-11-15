<?php

namespace App\Services\Crawler;

use App\Jobs\EnrichArticle;
use App\Models\Article;
use App\Models\CrawlRun;
use App\Models\Source;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bot de coleta. Roda por fonte: lê o feed, ignora o que já foi visto, entra no
 * site da matéria para pegar o texto completo e enfileira o enriquecimento.
 */
class CrawlerService
{
    public function __construct(
        private FeedReader $feeds,
        private ArticleExtractor $extractor,
    ) {}

    /** @return array<int, CrawlRun> */
    public function runDue(string $trigger = 'schedule'): array
    {
        if (! Settings::bool('crawler.enabled')) {
            Log::info('Crawler desligado nas configurações; nada a fazer.');

            return [];
        }

        $runs = [];

        foreach (Source::query()->where('enabled', true)->orderBy('id')->get() as $source) {
            if (! $source->isDue()) {
                continue;
            }

            $runs[] = $this->runSource($source, $trigger);
        }

        return $runs;
    }

    public function runSource(Source $source, string $trigger = 'manual'): CrawlRun
    {
        $run = CrawlRun::create([
            'source_id' => $source->id,
            'trigger' => $trigger,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $source->forceFill(['last_run_at' => now()])->save();

        try {
            $limit = $source->max_items ?: Settings::int('crawler.max_items_per_source');
            $items = $this->feeds->fetch($source->feed_url, $limit, $source->proxy_url);

            // O feed de uma fonte recém-cadastrada traz o arquivo dela (às vezes
            // anos de posts). Sem corte, cada item viraria um caso velho, e os
            // perfis de alerta seriam avisados agora de coisa antiga. O corte é
            // fixo na data de cadastro: na coleta seguinte o item continua de fora.
            // Fonte antiga que trocou de feed conta da troca: o feed novo também
            // traz o arquivo dele.
            $since = ($source->feed_changed_at ?? $source->created_at)?->copy()->subDays((int) config('secmon.crawler.backfill_days'));
            $items = array_values(array_filter(
                $items,
                fn (FeedItem $item) => ! $since || ! $item->publishedAt || $item->publishedAt->gte($since),
            ));

            $run->items_found = count($items);

            foreach ($items as $item) {
                try {
                    $outcome = $this->ingest($source, $item);
                } catch (Throwable $e) {
                    $run->items_failed++;
                    Log::warning('Falha ao ingerir item', [
                        'source' => $source->host,
                        'url' => $item->url,
                        'error' => CrawlerHttp::redact($e->getMessage()),
                    ]);

                    continue;
                }

                match ($outcome) {
                    'new' => $run->items_new++,
                    'duplicate' => $run->items_duplicate++,
                    default => null,
                };
            }

            $source->forceFill([
                'last_success_at' => now(),
                'last_status' => 'ok',
                'last_error' => null,
                'consecutive_failures' => 0,
                'articles_count' => $source->articles()->count(),
            ])->save();

            $run->forceFill(['status' => 'ok', 'finished_at' => now()])->save();
        } catch (Throwable $e) {
            // O erro do cURL pode trazer a URL do proxy, com a senha.
            $error = CrawlerHttp::redact($e->getMessage());

            $source->forceFill([
                'last_status' => 'failed',
                'last_error' => mb_substr($error, 0, 1000),
                'consecutive_failures' => $source->consecutive_failures + 1,
            ])->save();

            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'message' => mb_substr($error, 0, 1000),
            ])->save();

            Log::error('Crawler falhou na fonte', ['source' => $source->host, 'error' => $error]);
        }

        return $run->refresh();
    }

    /** @return 'new'|'duplicate' */
    private function ingest(Source $source, FeedItem $item): string
    {
        $urlHash = hash('sha256', $item->url);

        if (Article::query()->where('url_hash', $urlHash)->exists()) {
            return 'duplicate';
        }

        // O proxy da fonte vale também para a página da matéria, não só para o feed.
        $extracted = $this->extractor->extract($item->url, $source->proxy_url);

        // Quando o site não entrega o corpo (WAF ou página montada por
        // JavaScript), o resumo do feed ainda vira matéria — mas o painel de
        // coletas precisa mostrar que a análise saiu empobrecida.
        $content = $extracted['text'] !== '' ? $extracted['text'] : (string) $item->description;
        $extraction = match (true) {
            $extracted['ok'] => 'full',
            $content !== '' => 'summary',
            default => 'empty',
        };

        $contentHash = $content !== '' ? hash('sha256', preg_replace('/\s+/u', ' ', mb_strtolower($content))) : null;

        // Mesma matéria republicada em outra URL (muito comum entre agregadores).
        if ($contentHash && Article::query()->where('content_hash', $contentHash)->exists()) {
            return 'duplicate';
        }

        $article = Article::create([
            'source_id' => $source->id,
            'url' => $item->url,
            'url_hash' => $urlHash,
            'content_hash' => $contentHash,
            'guid' => $item->guid,
            'source_host' => $item->host() ?: $source->host,
            'feed_url' => $source->feed_url,
            'original_title' => $extracted['title'] ?: $item->title,
            'title' => $extracted['title'] ?: $item->title,
            'author' => $item->author ?: $extracted['author'],
            'published_at' => $item->publishedAt ?: $extracted['published_at'],
            'collected_at' => now(),
            'content' => $content,
            'summary' => $item->description ?: $extracted['description'],
            'status' => 'pending',
            'extraction' => $extraction,
            'content_chars' => mb_strlen($content),
            // Já sem a credencial do proxy: o extract() limpa a mensagem.
            'error' => $extracted['ok'] ? null : $extracted['error'],
        ]);

        EnrichArticle::dispatch($article->id)->onQueue('enrich');

        return 'new';
    }
}
