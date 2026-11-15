<?php

namespace Tests\Feature\Search;

use App\Services\Search\CaseIndex;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Elasticsearch de mentira para os testes: o cliente é o de verdade, só o
 * transporte HTTP é trocado por respostas combinadas de antemão. Cada
 * requisição que o código fez fica em `requests()`, para o teste conferir
 * método, endereço e corpo.
 *
 *   $es = FakeElasticsearch::answering([FakeElasticsearch::json(['count' => 3])]);
 *   $es->caseIndex()->count();          // 3
 *   $es->requests()[0]->getUri()->getPath();   // /secmon_cases/_count
 */
final class FakeElasticsearch
{
    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    private Client $client;

    /** @param  list<Response|\Throwable>  $responses  uma por requisição, na ordem */
    private function __construct(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $this->client = ClientBuilder::create()
            ->setHosts(['http://es.test:9200'])
            ->setHttpClient(new GuzzleClient(['handler' => $stack]))
            ->build();
    }

    /** @param  list<Response|\Throwable>  $responses */
    public static function answering(array $responses): self
    {
        return new self($responses);
    }

    /** Uma resposta do Elasticsearch (com o cabeçalho que o cliente exige). */
    public static function json(array|object $body = [], int $status = 200): Response
    {
        return new Response($status, [
            'X-Elastic-Product' => 'Elasticsearch',
            'Content-Type' => 'application/json',
        ], json_encode($body));
    }

    /** O `CaseIndex` do app, falando com este Elasticsearch de mentira. */
    public function caseIndex(): CaseIndex
    {
        $client = $this->client;

        return new class($client) extends CaseIndex
        {
            public function __construct(private Client $fake) {}

            public function client(): Client
            {
                return $this->fake;
            }
        };
    }

    /** @return list<RequestInterface> */
    public function requests(): array
    {
        return array_map(fn ($entry) => $entry['request'], $this->history);
    }

    /** O corpo JSON da requisição de ordem `$n` (0 é a primeira). */
    public function body(int $n = 0): array
    {
        return json_decode((string) $this->requests()[$n]->getBody(), true) ?? [];
    }

    public function path(int $n = 0): string
    {
        return $this->requests()[$n]->getUri()->getPath();
    }

    public function method(int $n = 0): string
    {
        return $this->requests()[$n]->getMethod();
    }
}
