<?php

namespace App\Services\IndexNow;

use App\Jobs\SubmitIndexNow;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * O aviso aos buscadores do IndexNow (Bing, Yandex, Seznam, Naver, Yep; a
 * busca do ChatGPT usa o índice do Bing em parte) de que um endereço do site
 * mudou: a matéria nova, a que voltou ao feed, a que saiu dele (410) ou foi
 * apagada (404) e, na fusão de matérias, a nova e as antigas, que redirecionam
 * (301). O Google não usa o IndexNow: ele lê o /sitemap.xml.
 *
 * Os endereços esperam num lote: o job (um só na fila) sai depois de
 * `delay_seconds`, e o mesmo endereço não entra de novo antes de
 * `dedup_minutes`. A fila e o resultado do último aviso ficam no cache.
 */
class IndexNow
{
    public const SETTING = 'indexnow.enabled';

    /** O protocolo aceita até 10 mil endereços por aviso. */
    public const PER_REQUEST = 10000;

    /** Até onde a fila cresce sem o aviso sair (um reanalyze inteiro recria todas as matérias). */
    private const MAX_PENDING = 50000;

    private const PENDING = 'indexnow:fila';

    private const RECENT = 'indexnow:recentes';

    private const LAST = 'indexnow:ultimo';

    private const LOCK = 'indexnow:trava';

    /** O que cada recusa quer dizer (indexnow.org/documentation). */
    private const REFUSALS = [
        400 => 'aviso mal formado',
        403 => 'a chave não confere: o buscador não achou o arquivo da chave com ela dentro',
        422 => 'endereço fora do host do site, ou chave fora do formato',
        429 => 'avisos demais: o buscador pediu para esperar',
    ];

    public function __construct(private IndexNowClient $client) {}

    /** Ligado em Configurações (o padrão: em produção), e só com o site num endereço público. */
    public function enabled(): bool
    {
        return Settings::bool(self::SETTING) && $this->host() !== null;
    }

    /**
     * Põe os endereços na fila do próximo aviso; devolve quantos entraram.
     * O que já entrou em `dedup_minutes` fica de fora, e endereço de outro host
     * também (o buscador recusaria o lote inteiro).
     *
     * @param  iterable<string>  $urls
     */
    public function queue(iterable $urls): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $host = $this->host();
        $urls = array_values(array_unique(array_filter([...$urls], fn ($url) => is_string($url) && parse_url($url, PHP_URL_HOST) === $host)));

        if ($urls === []) {
            return 0;
        }

        $added = Cache::lock(self::LOCK, 10)->block(5, function () use ($urls) {
            $now = now()->getTimestamp();
            $window = max(1, (int) config('secmon.indexnow.dedup_minutes')) * 60;
            $recent = array_filter((array) Cache::get(self::RECENT, []), fn ($at) => $at > $now - $window);
            $fresh = array_values(array_filter($urls, fn (string $url) => ! isset($recent[$url])));

            if ($fresh === []) {
                return 0;
            }

            foreach ($fresh as $url) {
                $recent[$url] = $now;
            }

            Cache::put(self::RECENT, $recent, $window);
            $this->savePending([...$this->pending(), ...$fresh]);

            return count($fresh);
        });

        if ($added > 0) {
            $this->dispatch();
        }

        return $added;
    }

    /**
     * Manda a fila num aviso só; devolve quantos endereços o buscador aceitou.
     * Falha passageira (sem resposta, 429, 5xx): os endereços voltam para a
     * fila e a exceção sobe, para o job tentar de novo. Recusa (400, 403, 422):
     * o aviso está errado (a chave, o host) e repetir não muda nada; o lote sai
     * da fila, e o motivo fica em Configurações e no log.
     */
    public function flush(): int
    {
        $urls = $this->take();

        if ($urls === []) {
            return 0;
        }

        $count = count($urls);

        try {
            $status = $this->client->submit((string) $this->host(), $this->key(), $this->keyLocation(), $urls);
        } catch (Throwable $e) {
            $this->giveBack($urls);
            $this->remember($count, null, $e->getMessage());
            Log::warning('IndexNow sem resposta: o aviso volta para a fila', ['enderecos' => $count, 'erro' => $e->getMessage()]);

            throw $e;
        }

        if ($status >= 200 && $status < 300) {
            $this->remember($count, $status, null);
            Log::info('IndexNow: aviso aceito', ['enderecos' => $count, 'status' => $status]);

            // Mais que um aviso de endereços (o reanalyze inteiro): o resto vai no seguinte.
            if ($this->pending() !== []) {
                $this->dispatch();
            }

            return $count;
        }

        $reason = self::REFUSALS[$status] ?? 'HTTP '.$status;
        $this->remember($count, $status, $reason);

        if ($status === 429 || $status >= 500) {
            $this->giveBack($urls);
            Log::warning('IndexNow fora do ar: o aviso volta para a fila', ['enderecos' => $count, 'status' => $status]);

            throw new RuntimeException("IndexNow respondeu HTTP {$status}");
        }

        Log::warning('IndexNow recusou o aviso', ['enderecos' => $count, 'status' => $status, 'motivo' => $reason]);

        return 0;
    }

    /**
     * Para Admin → Configurações: o último aviso, o que espera e onde fica a chave.
     *
     * @return array{enabled: bool, host: ?string, key_file: string, pending: int, last: ?array{at: string, urls: int, status: ?int, error: ?string}}
     */
    public function status(): array
    {
        $last = Cache::get(self::LAST);

        return [
            'enabled' => $this->enabled(),
            'host' => $this->host(),
            'key_file' => '/'.$this->key().'.txt',
            'pending' => count($this->pending()),
            'last' => is_array($last) ? $last : null,
        ];
    }

    /**
     * O host do APP_URL, se for público (https e com domínio). Em casa
     * (localhost, um IP), o IndexNow recusaria o aviso: ele nem sai.
     */
    public function host(): ?string
    {
        $url = parse_url((string) config('app.url'));
        $host = strtolower((string) ($url['host'] ?? ''));

        return ($url['scheme'] ?? '') === 'https' && str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP) ? $host : null;
    }

    public function key(): string
    {
        return (string) config('secmon.indexnow.key');
    }

    /** O arquivo da chave fica na raiz do site: assim ela vale para todos os endereços dele. */
    public function keyLocation(): string
    {
        return rtrim((string) config('app.url'), '/').'/'.$this->key().'.txt';
    }

    private function dispatch(): void
    {
        SubmitIndexNow::dispatch()->delay(now()->addSeconds(max(0, (int) config('secmon.indexnow.delay_seconds'))));
    }

    /** @return list<string> */
    private function take(): array
    {
        return Cache::lock(self::LOCK, 10)->block(5, function () {
            $pending = $this->pending();
            $this->savePending(array_slice($pending, self::PER_REQUEST));

            return array_slice($pending, 0, self::PER_REQUEST);
        });
    }

    /** @param  list<string>  $urls */
    private function giveBack(array $urls): void
    {
        Cache::lock(self::LOCK, 10)->block(5, fn () => $this->savePending([...$urls, ...$this->pending()]));
    }

    /** @return list<string> */
    private function pending(): array
    {
        return array_values((array) Cache::get(self::PENDING, []));
    }

    /** @param  list<string>  $urls */
    private function savePending(array $urls): void
    {
        $urls = array_slice(array_values(array_unique($urls)), -self::MAX_PENDING);
        $urls === [] ? Cache::forget(self::PENDING) : Cache::put(self::PENDING, $urls, now()->addDay());
    }

    private function remember(int $urls, ?int $status, ?string $error): void
    {
        Cache::forever(self::LAST, ['at' => now()->toIso8601String(), 'urls' => $urls, 'status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 300)]);
    }
}
