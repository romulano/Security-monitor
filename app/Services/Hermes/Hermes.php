<?php

namespace App\Services\Hermes;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A única porta de saída para o hermes, a ponte de IA do servidor. Ele fala o
 * protocolo da OpenAI (`/v1/chat/completions`) e roda sem ferramentas: uma
 * mensagem de sistema, uma do usuário e a resposta em texto.
 *
 * Todo serviço que usa IA chama `chat()` (ou `json()`), em vez de montar a
 * própria requisição: URL, token, modelo, tempo limite e o texto do erro HTTP
 * ficam num lugar só.
 */
final class Hermes
{
    /** Folga da requisição sobre o tempo que o hermes recebe em `timeout_seconds`. */
    public const DEFAULT_MARGIN = 15;

    public static function url(): string
    {
        return rtrim((string) Settings::get('enricher.hermes_url'), '/');
    }

    public static function configured(): bool
    {
        return self::url() !== '';
    }

    /** O cliente HTTP do hermes: JSON, conexão em 3 s e o token, se houver. */
    public static function http(int $timeout): PendingRequest
    {
        $request = Http::acceptJson()->connectTimeout(3)->timeout($timeout);
        $token = (string) config('secmon.enricher.hermes.token');

        return $token !== '' ? $request->withToken($token) : $request;
    }

    /**
     * Uma conversa de uma pergunta só; devolve o texto da resposta.
     *
     * @param  string|list<array<string, mixed>>  $user  o texto, ou as partes (texto e imagens) da mensagem
     * @param  int  $timeout  segundos que o hermes recebe para responder
     * @param  int  $margin  segundos a mais que a requisição espera além do `$timeout`
     * @param  array<string, string|int>  $extra  campos a mais do corpo (`reasoning_effort`, `tools`, `max_turns`); vazio não vai
     *
     * @throws HermesLimitReached a conta da IA bateu o limite de uso (a exceção diz quando volta)
     * @throws RuntimeException resposta HTTP de erro ou sem texto
     */
    public static function chat(string $system, string|array $user, int $timeout, int $margin = self::DEFAULT_MARGIN, array $extra = []): string
    {
        $response = self::http($timeout + $margin)->post(self::url().'/v1/chat/completions', array_filter([
            'model' => (string) Settings::get('enricher.hermes_model'),
            ...$extra,
            'timeout_seconds' => $timeout,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ], fn ($v) => $v !== ''));

        if (! $response->successful()) {
            $message = Arr::get($response->json() ?? [], 'error.message') ?: mb_substr($response->body(), 0, 300);

            throw HermesLimitReached::from($response->status(), (string) $message)
                ?? new RuntimeException('hermes HTTP '.$response->status().': '.$message);
        }

        $content = Arr::get($response->json(), 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Resposta do hermes sem conteúdo utilizável.');
        }

        return $content;
    }

    /**
     * O esforço das chamadas de decisão (uma nota, um rótulo, um sim ou não),
     * para mandar em `$extra`: sem ele, vale o padrão do modelo, que raciocina
     * bem mais do que a decisão pede. Vazio na configuração, nada vai.
     *
     * @return array<string, string>
     */
    public static function light(): array
    {
        $effort = (string) config('secmon.enricher.hermes.light_effort');

        return $effort !== '' ? ['reasoning_effort' => $effort] : [];
    }

    /**
     * @param  array<string, string|int>  $extra  campos a mais do corpo, como em chat()
     * @return array<string, mixed> o objeto JSON da resposta
     */
    public static function json(string $system, string $user, int $timeout = 180, array $extra = []): array
    {
        if (! self::configured()) {
            throw new RuntimeException('o hermes não está configurado');
        }

        return self::decode(self::chat($system, $user, $timeout, margin: 30, extra: $extra));
    }

    /** O primeiro objeto JSON do texto (o modelo às vezes cerca a resposta com prosa ou cercas de código). */
    public static function decode(string $content): array
    {
        return self::object($content) ?? throw new RuntimeException('a IA não devolveu JSON utilizável');
    }

    /** Como decode(), mas nulo quando não há objeto JSON no texto: quem chama escolhe a mensagem do erro. */
    public static function object(string $content): ?array
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        $json = $start !== false && $end > $start ? preg_replace('/[\x00-\x1F]+/', ' ', substr($content, $start, $end - $start + 1)) : '';
        $data = json_decode((string) $json, true);

        return is_array($data) ? $data : null;
    }

    /** Uma frase da IA para mostrar ao cliente: uma linha, sem marcação, até `$max` caracteres. */
    public static function sentence(mixed $text, int $max = 300): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text))), 0, $max);
    }
}
