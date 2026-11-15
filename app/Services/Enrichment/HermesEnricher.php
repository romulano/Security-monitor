<?php

namespace App\Services\Enrichment;

use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Hermes\Hermes;
use App\Services\Review\ReportReviewer;
use App\Services\Review\ReviewVerdict;
use App\Support\Settings;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

/**
 * Driver hermes: a ponte HTTP local para o Claude Code da máquina
 * (~/job/hermes). Fala o protocolo da OpenAI (`/v1/chat/completions`), mas o
 * CLI por trás não aceita `response_format` — o schema vai no prompt de
 * sistema e o EnrichmentNormalizer tolera a cerca de código.
 */
class HermesEnricher implements ChatDriver
{
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public function name(): string
    {
        return 'hermes';
    }

    public function isReady(): bool
    {
        return Hermes::configured();
    }

    /** O parecer do revisor com o esforço escolhido no admin, como a análise. */
    public function chat(string $system, string $user, int $timeout): string
    {
        return Hermes::chat($system, $user, $timeout, extra: ['reasoning_effort' => (string) Settings::get('enricher.hermes_effort')]);
    }

    public function chatTimeout(): int
    {
        return (int) config('secmon.enricher.hermes.timeout');
    }

    public function chatReserve(): int
    {
        return Hermes::DEFAULT_MARGIN;
    }

    public function enrich(Article $article, ?NewsCase $existing = null): EnrichmentResult
    {
        return EnrichmentPrompt::withPatch($article, $existing, fn (bool $patch, ?int $seconds) => $this->analyze($article, $existing, $patch, $seconds));
    }

    /** @param  ?int  $seconds  o tempo que sobra no job, na refação depois de um trecho que não encaixou */
    private function analyze(Article $article, ?NewsCase $existing, bool $patch, ?int $seconds): EnrichmentResult
    {
        $timeout = (int) config('secmon.enricher.hermes.timeout');

        if ($seconds !== null) {
            $timeout = min($timeout, $seconds - Hermes::DEFAULT_MARGIN);
        }

        $content = Hermes::chat(
            self::system($patch),
            EnrichmentPrompt::user($article, $existing, $patch),
            $timeout,
            extra: self::extra(),
        );

        return EnrichmentNormalizer::fromJson($content, $article, $this->name(), $existing);
    }

    /**
     * A refação que o revisor do relatório pediu: a mesma mensagem da análise,
     * com o pedido de ajuste no fim (o escopo, os problemas e o rascunho anterior).
     * Sempre com o relatório inteiro: o pedido cita `case_summary` e o rascunho o traz inteiro.
     */
    public function revise(Article $article, ?NewsCase $existing, EnrichmentResult $draft, string $mode, ReviewVerdict $verdict): EnrichmentResult
    {
        $content = Hermes::chat(
            self::system(),
            EnrichmentPrompt::user($article, $existing).ReportReviewer::redoRequest($draft, $mode, $verdict),
            (int) config('secmon.enricher.hermes.timeout'),
            extra: self::extra(),
        );

        return EnrichmentNormalizer::fromJson($content, $article, $this->name());
    }

    /**
     * O esforço e, com a pesquisa ligada, as ferramentas de busca na web: as
     * regras da análise mandam procurar o boletim do fornecedor e o PR da
     * correção antes de escrever.
     *
     * @return array<string, string|int>
     */
    public static function extra(): array
    {
        $effort = (string) Settings::get('enricher.hermes_effort');

        if (! config('secmon.enricher.hermes.research')) {
            return ['reasoning_effort' => $effort];
        }

        // Abaixo do piso, sobe; vazio fica (é o padrão do hermes/CLI, que não é o `low`).
        $floor = (string) config('secmon.enricher.hermes.research_effort');
        $rank = array_flip(self::EFFORTS);

        if ($effort !== '' && isset($rank[$effort], $rank[$floor]) && $rank[$effort] < $rank[$floor]) {
            $effort = $floor;
        }

        return [
            'reasoning_effort' => $effort,
            'tools' => 'WebSearch,WebFetch',
            'max_turns' => (int) config('secmon.enricher.hermes.research_turns'),
        ];
    }

    /**
     * Estado da ponte para a tela de configurações, sem gastar IA: o /healthz
     * diz se está no ar, e um POST sem `messages` testa o token — o hermes
     * autentica antes de ler o corpo (401 = token errado, 400 = token aceito).
     *
     * @return array{online: bool, error?: string, token?: string, modelo?: string, esforco?: string, chamadas?: int, falhas?: int, custo_usd?: float, em_voo?: int, paralelas?: int}
     */
    public function status(): array
    {
        try {
            $health = Hermes::http(3)->get(Hermes::url().'/healthz');
        } catch (Throwable $e) {
            return ['online' => false, 'error' => $e->getMessage()];
        }

        if (! $health->successful() || Arr::get($health->json() ?? [], 'servico') !== 'hermes') {
            return ['online' => false, 'error' => 'HTTP '.$health->status().' — não parece ser o hermes'];
        }

        try {
            $probe = Hermes::http(3)->post(Hermes::url().'/v1/chat/completions', ['messages' => []])->status();
            $token = match ($probe) {
                400 => filled(config('secmon.enricher.hermes.token')) ? 'aceito' : 'não exigido',
                401 => filled(config('secmon.enricher.hermes.token')) ? 'recusado' : 'exigido e ausente',
                default => 'HTTP '.$probe,
            };
        } catch (Throwable) {
            $token = 'não verificado';
        }

        return ['online' => true, 'token' => $token] + Arr::only($health->json(), [
            'modelo', 'esforco', 'chamadas', 'falhas', 'custo_usd', 'em_voo', 'paralelas',
        ]);
    }

    /**
     * Modelos que a ponte anuncia em /v1/models, sem os nomes genéricos
     * (`claude-code` e afins), que no hermes significam "o padrão do
     * hermes.conf" — o select já oferece isso como valor vazio.
     *
     * @return list<string>
     */
    public function models(): array
    {
        $response = Hermes::http(3)->get(Hermes::url().'/v1/models');

        if (! $response->successful()) {
            throw new RuntimeException('hermes HTTP '.$response->status());
        }

        return array_values(array_filter(
            Arr::pluck($response->json('data') ?? [], 'id'),
            fn ($id) => is_string($id) && ! in_array(mb_strtolower($id), ['', 'claude-code', 'default', 'padrao', 'padrão'], true),
        ));
    }

    /** O hermes não aceita `response_format`: o schema vai no fim das regras. */
    public static function system(bool $patch = false): string
    {
        return EnrichmentPrompt::system($patch)."\n\n".self::reply($patch);
    }

    /** Parte fixa: o normalizador depende deste formato. */
    public static function reply(bool $patch = false): string
    {
        $schema = json_encode(EnrichmentPrompt::schema($patch), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'Reply with a single JSON object and nothing else — no prose, '.
            "no code fence. It must validate against this JSON Schema:\n".$schema;
    }
}
