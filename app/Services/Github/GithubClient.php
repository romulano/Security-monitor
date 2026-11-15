<?php

namespace App\Services\Github;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * A API oficial do GitHub, só leitura do que é público. A busca em código
 * exige token (qualquer um, sem escopo); a de repositórios funciona sem, mas
 * com limite menor. Os limites vêm nos cabeçalhos: quando acabam, a chamada
 * espera a janela voltar (até `max_wait_seconds`) ou desiste com
 * GithubRateLimited, e o que faltou fica para a próxima varredura.
 */
class GithubClient
{
    /** @var array<string, array{remaining: int, reset: int}> limite conhecido por grupo (search, code_search, core) */
    private array $limits = [];

    public function hasToken(): bool
    {
        return filled(config('secmon.github.token'));
    }

    /**
     * Arquivos públicos que contêm o termo, com o trecho onde ele aparece.
     *
     * @return array{total: int, items: list<array>}
     */
    public function searchCode(string $query): array
    {
        if (! $this->hasToken()) {
            throw new GithubUnavailable('a busca em código do GitHub exige token (GITHUB_SEARCH_TOKEN)');
        }

        // text-match: o GitHub devolve o trecho de cada arquivo em `text_matches`.
        $response = $this->get('/search/code', ['q' => $query, 'per_page' => $this->perPage()], 'application/vnd.github.text-match+json', 'code_search');

        return $response ? ['total' => (int) $response->json('total_count'), 'items' => (array) $response->json('items', [])] : ['total' => 0, 'items' => []];
    }

    /** @return array{total: int, items: list<array>} */
    public function searchRepos(string $query): array
    {
        $response = $this->get('/search/repositories', ['q' => $query, 'per_page' => $this->perPage(), 'sort' => 'updated'], bucket: 'search');

        return $response ? ['total' => (int) $response->json('total_count'), 'items' => (array) $response->json('items', [])] : ['total' => 0, 'items' => []];
    }

    /**
     * Repositórios públicos de uma organização ou de um usuário, do mais novo
     * para o mais velho. Nulo se o login não existe.
     *
     * @return ?list<array>
     */
    public function ownerRepos(string $login): ?array
    {
        $response = $this->get('/users/'.rawurlencode($login).'/repos', ['per_page' => 100, 'sort' => 'created', 'direction' => 'desc', 'type' => 'owner']);

        return $response ? (array) $response->json() : null;
    }

    /** O repositório, se for público (nulo: não existe ou é privado). */
    public function repo(string $fullName): ?array
    {
        $response = $this->get('/repos/'.$this->repoPath($fullName));

        return $response ? (array) $response->json() : null;
    }

    /** O arquivo ainda está lá? Nulo quando não deu para saber (limite, erro). */
    public function fileExists(string $fullName, string $path): ?bool
    {
        try {
            $response = $this->get('/repos/'.$this->repoPath($fullName).'/contents/'.implode('/', array_map('rawurlencode', explode('/', $path))));
        } catch (GithubRateLimited|RuntimeException) {
            return null;
        }

        return $response !== null;
    }

    private function repoPath(string $fullName): string
    {
        return implode('/', array_map('rawurlencode', array_slice(explode('/', $fullName, 2), 0, 2)));
    }

    /**
     * GET na API. Nulo para 404 (não existe ou não é público) e para 422 (a
     * busca recusou o termo); a exceção explica o resto.
     */
    private function get(string $path, array $query = [], string $accept = 'application/vnd.github+json', string $bucket = 'core'): ?Response
    {
        for ($attempt = 1; ; $attempt++) {
            $this->waitFor($bucket);

            $request = Http::withHeaders(['Accept' => $accept, 'X-GitHub-Api-Version' => '2022-11-28'])
                ->withUserAgent((string) config('secmon.crawler.user_agent', 'SecurityMonitorBot/1.0 (+https://example.com)'))
                ->connectTimeout(5)
                ->timeout((int) config('secmon.github.timeout', 20));

            if ($this->hasToken()) {
                $request = $request->withToken((string) config('secmon.github.token'));
            }

            $response = $request->get(rtrim((string) config('secmon.github.api_url'), '/').$path, $query);
            $this->remember($bucket, $response);

            if ($response->successful()) {
                return $response;
            }

            if (in_array($response->status(), [404, 422], true)) {
                return null;
            }

            if ($response->status() === 401) {
                throw new GithubUnavailable('o GitHub recusou o token (GITHUB_SEARCH_TOKEN inválido ou revogado)');
            }

            // Limite primário (remaining 0) ou secundário (retry-after): espera e tenta mais uma vez.
            if (in_array($response->status(), [403, 429], true) && ($wait = $this->retryAfter($bucket, $response)) !== null) {
                if ($attempt >= 2 || $wait > (int) config('secmon.github.max_wait_seconds', 65)) {
                    throw new GithubRateLimited($wait);
                }

                Sleep::for($wait)->seconds();

                continue;
            }

            throw new RuntimeException('GitHub HTTP '.$response->status().': '.mb_substr((string) ($response->json('message') ?? $response->body()), 0, 200));
        }
    }

    /** Antes de chamar: se o grupo já esgotou, espera a janela voltar (ou desiste). */
    private function waitFor(string $bucket): void
    {
        $limit = $this->limits[$bucket] ?? null;

        if (! $limit || $limit['remaining'] > 0) {
            return;
        }

        $wait = $limit['reset'] - time() + 1;

        if ($wait <= 0) {
            return;
        }

        if ($wait > (int) config('secmon.github.max_wait_seconds', 65)) {
            throw new GithubRateLimited($wait);
        }

        Sleep::for($wait)->seconds();
        unset($this->limits[$bucket]);
    }

    private function remember(string $bucket, Response $response): void
    {
        $remaining = $response->header('x-ratelimit-remaining');
        $reset = $response->header('x-ratelimit-reset');

        if ($remaining !== '' && $reset !== '') {
            $this->limits[$bucket] = ['remaining' => (int) $remaining, 'reset' => (int) $reset];
        }
    }

    /** Segundos até poder tentar de novo, ou nulo se o 403 não é de limite (repositório bloqueado, por exemplo). */
    private function retryAfter(string $bucket, Response $response): ?int
    {
        if (($after = $response->header('retry-after')) !== '') {
            return max(1, (int) $after);
        }

        $limit = $this->limits[$bucket] ?? null;

        return $limit && $limit['remaining'] === 0 ? max(1, $limit['reset'] - time() + 1) : null;
    }

    private function perPage(): int
    {
        return max(1, min(100, (int) config('secmon.github.per_page', 30)));
    }
}
