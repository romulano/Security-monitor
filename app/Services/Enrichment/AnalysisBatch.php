<?php

namespace App\Services\Enrichment;

use Anthropic\Messages\Batches\BatchCreateParams\Request;
use Anthropic\Messages\Batches\BatchCreateParams\Request\Params;
use Anthropic\Messages\Batches\MessageBatchSucceededResult;
use App\Jobs\CollectAnalysisBatch;
use App\Jobs\EnrichArticle;
use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Crawler\CaseResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Reprocessamento em massa pelo lote da API da Anthropic (Message Batches):
 * metade do preço de cada token, com a resposta em minutos ou horas em vez de
 * segundos. Só para o `secmon:reanalyze --batch` com o driver `claude`: a
 * análise do dia a dia precisa sair na hora (os alertas dependem dela).
 *
 * Cada artigo depende do relatório que os anteriores da mesma história
 * escreveram, então o lote vai em rodadas: cada rodada leva no máximo um
 * artigo por matéria, na ordem de publicação. Na coleta, o artigo cuja
 * matéria mudou desde o envio volta para a rodada seguinte, já com o
 * relatório novo; a resposta que não serve (erro, expirada, pesquisa
 * pausada, corte no teto) cai na análise normal, a preço cheio.
 *
 * O resultado não é gravado aqui: fica no cache para o EnrichArticle do
 * artigo, que segue o caminho de sempre (revisor, CaseWriter, alertas) sem
 * chamar a IA de novo. O estado da execução fica no cache (uma por vez).
 */
class AnalysisBatch
{
    /** Rodadas por execução; o que sobra depois disso vai para a análise normal. */
    public const MAX_ROUNDS = 8;

    /** Pedidos por lote (a API aceita até 100 mil; o resto fica para a rodada seguinte). */
    private const MAX_REQUESTS = 2000;

    /** O estado e as respostas guardadas vivem até isto: o lote da API expira em 24 h. */
    private const TTL_HOURS = 72;

    /** Esperando os jobs de uma rodada por mais que isto, a execução segue sem eles. */
    private const APPLY_WAIT_MINUTES = 120;

    private const RUN_KEY = 'analysis-batch:run';

    private ?ClaudeEnricher $claude = null;

    /** Nos testes, o driver com o cliente falso da API. */
    public function withEnricher(ClaudeEnricher $claude): static
    {
        $this->claude = $claude;

        return $this;
    }

    private function claude(): ClaudeEnricher
    {
        return $this->claude ??= new ClaudeEnricher;
    }

    /** @return ?array{batch: ?string, round: int, pending: list<int>, sent: array<int, array{case: ?int, stamp: ?string}>, applying: list<int>, applying_since: ?int} */
    public function active(): ?array
    {
        return Cache::get(self::RUN_KEY);
    }

    /**
     * Os artigos que a execução ainda vai analisar: o secmon:enrich-pending os
     * pula, senão eles iriam para a análise normal antes do lote.
     *
     * @return list<int>
     */
    public function reserved(): array
    {
        $run = $this->active();

        return $run ? array_values(array_unique([...$run['pending'], ...array_keys($run['sent'])])) : [];
    }

    /**
     * Abre a execução com os artigos (já soltos das matérias, `pending`) e
     * envia a primeira rodada; o coletor passa a cada 5 minutos.
     *
     * @param  Collection<int, int>|list<int>  $articleIds
     *
     * @throws RuntimeException outra execução em andamento ou driver sem chave
     */
    public function start(Collection|array $articleIds): array
    {
        if ($this->active()) {
            throw new RuntimeException('Já há um reprocessamento pelo lote em andamento.');
        }

        if (! $this->claude()->isReady()) {
            throw new RuntimeException('ANTHROPIC_API_KEY não configurada.');
        }

        $this->save(['batch' => null, 'round' => 0, 'pending' => array_values(array_map('intval', collect($articleIds)->all())), 'sent' => [], 'applying' => [], 'applying_since' => null]);

        // A primeira rodada não saiu (a API recusou, fora do ar): nada fica reservado.
        try {
            $this->submit();
        } catch (Throwable $e) {
            Cache::forget(self::RUN_KEY);

            throw $e;
        }

        CollectAnalysisBatch::dispatch()->delay(now()->addMinutes(CollectAnalysisBatch::EVERY_MINUTES));

        return $this->active() ?? [];
    }

    /**
     * Uma passada do coletor: `waiting` (o lote ainda roda), `applying` (os
     * jobs da rodada ainda gravam), `submitted` (nova rodada enviada) ou `done`.
     */
    public function collect(): string
    {
        $run = $this->active();

        if (! $run) {
            return 'done';
        }

        if ($run['batch']) {
            return $this->receive($run);
        }

        $stuck = $run['applying_since'] && now()->getTimestamp() - $run['applying_since'] > self::APPLY_WAIT_MINUTES * 60;

        if ($run['applying'] !== [] && ! $stuck && Article::query()->whereIn('id', $run['applying'])->where('status', 'pending')->exists()) {
            return 'applying';
        }

        if ($run['pending'] === []) {
            Cache::forget(self::RUN_KEY);

            return 'done';
        }

        if ($run['round'] >= self::MAX_ROUNDS) {
            Log::warning('Lote da API: rodadas esgotadas; o resto vai para a análise normal', ['artigos' => count($run['pending'])]);
            array_map(self::normal(...), $run['pending']);
            Cache::forget(self::RUN_KEY);

            return 'done';
        }

        return $this->submit() ? 'submitted' : 'done';
    }

    /** O resultado do lote para o artigo, uma vez só (o job o consome). @return ?array{text: string, case: ?int, stamp: ?string} */
    public static function take(int $articleId): ?array
    {
        return Cache::pull(self::articleKey($articleId));
    }

    /**
     * O que a análise leu da matéria: título, relatório nos dois idiomas e
     * indicadores. Diferente na coleta, a resposta foi escrita sobre um
     * relatório que não é mais o atual e não serve. A explicação da falha e os
     * exploits mudam a matéria sem mudar isto.
     */
    public static function stamp(?NewsCase $case): ?string
    {
        return $case ? hash('sha256', json_encode([$case->title, $case->title_pt, $case->summary, $case->summary_pt, $case->indicators], JSON_UNESCAPED_UNICODE)) : null;
    }

    /** Envia a rodada seguinte; falso quando não sobrou artigo para enviar (a execução termina). */
    private function submit(): bool
    {
        $run = $this->active();
        $articles = Article::query()
            ->whereIn('id', $run['pending'])
            ->where('status', 'pending')
            ->orderByRaw('coalesce(published_at, collected_at)')
            ->orderBy('id')
            ->get();

        $resolver = app(CaseResolver::class);
        [$requests, $sent, $rest, $cases] = [[], [], [], []];

        foreach ($articles as $article) {
            $existing = EnrichArticle::existingCase($article, $resolver);

            // Um artigo por matéria por rodada: o próximo lê o relatório que este escrever.
            if (($existing && isset($cases[$existing->id])) || count($requests) >= self::MAX_REQUESTS) {
                $rest[] = $article->id;

                continue;
            }

            if ($existing) {
                $cases[$existing->id] = true;
            }

            $requests[] = Request::with(customID: 'article-'.$article->id, params: Params::with(...$this->claude()->batchRequest($article, $existing)));
            $sent[$article->id] = ['case' => $existing?->id, 'stamp' => self::stamp($existing)];
        }

        if ($requests === []) {
            Cache::forget(self::RUN_KEY);

            return false;
        }

        $batch = $this->claude()->batches()->create(requests: $requests);

        Log::info('Lote da API: rodada enviada', ['lote' => $batch->id, 'rodada' => $run['round'] + 1, 'artigos' => count($requests), 'esperando' => count($rest)]);
        $this->save(['batch' => $batch->id, 'round' => $run['round'] + 1, 'pending' => $rest, 'sent' => $sent, 'applying' => [], 'applying_since' => null]);

        return true;
    }

    /** Lê o lote que terminou e encaminha cada artigo: o job com a resposta, a rodada seguinte ou a análise normal. */
    private function receive(array $run): string
    {
        $batches = $this->claude()->batches();

        if ($batches->retrieve($run['batch'])->processingStatus !== 'ended') {
            return 'waiting';
        }

        $results = [];

        foreach ($batches->resultsStream($run['batch']) as $item) {
            $results[$item->customID] = $item->result;
        }

        $resolver = app(CaseResolver::class);
        [$next, $applying] = [$run['pending'], []];

        foreach ($run['sent'] as $id => $sent) {
            $article = Article::query()->find($id);

            // Analisado por outro caminho nesse meio-tempo (Refazer, a coleta): nada a fazer.
            if (! $article || $article->status !== 'pending') {
                continue;
            }

            $result = $results['article-'.$id] ?? null;

            if (! $result instanceof MessageBatchSucceededResult) {
                self::normal($id);

                continue;
            }

            $existing = EnrichArticle::existingCase($article, $resolver);

            if ($existing?->id !== $sent['case'] || self::stamp($existing) !== $sent['stamp']) {
                $next[] = $id;

                continue;
            }

            try {
                $text = ClaudeEnricher::finalText($result->message);
            } catch (Throwable $e) {
                Log::info('Lote da API: resposta sem a análise; vai para a análise normal', ['artigo' => $id, 'erro' => $e->getMessage()]);
                self::normal($id);

                continue;
            }

            Cache::put(self::articleKey($id), ['text' => $text, 'case' => $sent['case'], 'stamp' => $sent['stamp']], now()->addHours(self::TTL_HOURS));
            EnrichArticle::dispatch($id, 'refazer-materia')->onQueue('enrich');
            $applying[] = $id;
        }

        Log::info('Lote da API: rodada recebida', ['lote' => $run['batch'], 'gravando' => count($applying), 'proxima_rodada' => count($next) - count($run['pending'])]);
        $this->save(['batch' => null, 'sent' => [], 'pending' => array_values(array_unique($next)), 'applying' => $applying, 'applying_since' => now()->getTimestamp()] + $run);

        return 'applying';
    }

    /** A análise de sempre, a preço cheio. */
    private static function normal(int $articleId): void
    {
        Cache::forget(self::articleKey($articleId));
        EnrichArticle::dispatch($articleId, 'refazer-materia')->onQueue('enrich');
    }

    private function save(array $run): void
    {
        Cache::put(self::RUN_KEY, $run, now()->addHours(self::TTL_HOURS));
    }

    private static function articleKey(int $articleId): string
    {
        return 'analysis-batch:article:'.$articleId;
    }
}
