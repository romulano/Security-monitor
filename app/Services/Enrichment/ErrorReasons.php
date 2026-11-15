<?php

namespace App\Services\Enrichment;

use App\Jobs\ExplainAnalysisError;
use App\Models\ErrorExplanation;
use App\Services\Hermes\Hermes;
use App\Support\Prompts;

/**
 * O motivo de um erro de análise em linguagem clara, para Admin → Coletas. Primeiro
 * o mapa fixo, sem IA, dos erros conhecidos; o que ele não conhece vai ao hermes
 * (ExplainAnalysisError), uma vez por assinatura do erro. Sem explicação ainda, a
 * tela mostra o texto técnico com "motivo ainda não explicado".
 */
class ErrorReasons
{
    /** A etapa na aba Prompts. */
    public const STAGE = 'erros-explicar';

    /**
     * Padrão => motivo. Vale o primeiro que casa: o limite de uso do hermes ("hermes
     * HTTP 502: You've hit your session limit") vem antes do hermes fora do ar, e a
     * página que não abriu ("HTTP 403" puro) antes da chave recusada.
     */
    private const KNOWN = [
        '/control character/i' => 'A IA devolveu a resposta com uma quebra de linha fora do formato; o texto não pôde ser lido.',
        '/limite de tokens|max_tokens|context length/i' => 'A resposta da IA foi cortada no limite de tamanho (tokens) antes de terminar.',
        '/recusou a análise|\brefusal\b|refused to|filtro do provedor|content_filter/i' => 'O modelo se recusou a analisar o artigo (filtro de conteúdo do provedor).',
        '/hit your [a-z ]*limit|usage limit|rate.?limit|HTTP 429|overloaded|HTTP 529/i' => 'A conta da IA bateu o limite de uso; vale refazer depois que o limite renovar.',
        '/timed? ?out|timeout|cURL error 28|tempo esgotado/i' => 'A IA demorou demais para responder e o tempo esgotou.',
        '/^HTTP \d{3}$|corpo vazio|HTML ilegível|texto principal muito curto|Leitura dos prints/i' => 'A página do artigo não abriu direito (o site bloqueou ou veio sem texto); só o resumo do feed foi lido.',
        '/sem credencial|API_KEY não configurada|HTTP 401|unauthorized|x-api-key|authentication/i' => 'O driver da IA está sem chave configurada, ou a chave foi recusada.',
        '/hermes HTTP 50[0234]|hermes não está configurado|Connection refused|Could not resolve|cURL error [67]\b/i' => 'O hermes (a IA local) estava fora do ar ou sem vaga para atender.',
        '/não contém JSON|JSON utilizável|sem conteúdo utilizável|Resposta vazia|não é um objeto|JSON inválido/i' => 'A IA respondeu fora do formato combinado e a resposta não pôde ser lida.',
    ];

    /** O motivo do mapa fixo, ou null quando o erro é desconhecido. */
    public function known(string $error): ?string
    {
        foreach (self::KNOWN as $pattern => $reason) {
            if (preg_match($pattern, $error)) {
                return $reason;
            }
        }

        return null;
    }

    /** O erro sem endereços, ids e números: o mesmo erro em outro artigo tem a mesma assinatura. */
    public static function signature(string $error): string
    {
        $signature = mb_strtolower($error);
        $signature = (string) preg_replace('#https?://\S+#', '<url>', $signature);
        $signature = (string) preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/', '<id>', $signature);
        $signature = (string) preg_replace('/\b(?=[0-9a-z_-]*\d)[0-9a-z_-]{16,}\b/', '<id>', $signature);
        $signature = (string) preg_replace('/\d+/', '#', $signature);

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $signature)), 0, 300);
    }

    /**
     * O motivo de cada erro, com uma consulta só para os que o mapa não conhece.
     *
     * @param  iterable<string>  $errors
     * @return array<string, array{text: ?string, source: 'mapa'|'ia'|null}>
     */
    public function reasons(iterable $errors): array
    {
        $reasons = [];
        $unknown = [];

        foreach ($errors as $error) {
            $known = $this->known($error);
            $reasons[$error] = ['text' => $known, 'source' => $known !== null ? 'mapa' : null];

            if ($known === null) {
                $unknown[sha1(self::signature($error))][] = $error;
            }
        }

        if ($unknown !== []) {
            ErrorExplanation::query()->whereIn('signature_hash', array_keys($unknown))->where('status', 'done')
                ->pluck('explanation', 'signature_hash')
                ->each(function (string $text, string $hash) use ($unknown, &$reasons) {
                    foreach ($unknown[$hash] as $error) {
                        $reasons[$error] = ['text' => $text, 'source' => 'ia'];
                    }
                });
        }

        return $reasons;
    }

    /** Ligada na aba Prompts e com o hermes configurado. */
    public function enabled(): bool
    {
        return Hermes::configured() && Prompts::stageOn(self::STAGE);
    }

    /**
     * Pede ao hermes o motivo de um erro que o mapa não conhece, se a assinatura
     * ainda não tem explicação. A que ficou sem (falhou ou a etapa estava
     * desligada) volta a ser pedida depois de um dia. Vai só o erro, o driver, a
     * etapa e o tamanho do artigo: nunca o texto dele.
     */
    public function queue(string $error, string $driver, string $stage, int $articleChars): bool
    {
        if ($this->known($error) !== null || ! $this->enabled()) {
            return false;
        }

        $signature = self::signature($error);
        $row = ErrorExplanation::query()->firstOrCreate(['signature_hash' => sha1($signature)], ['signature' => $signature]);

        if (! $row->wasRecentlyCreated && ($row->status === 'done' || $row->updated_at->gt(now()->subDay()))) {
            return false;
        }

        $row->forceFill(['status' => 'pending', 'error' => null, 'updated_at' => now()])->save();
        ExplainAnalysisError::dispatch($row->signature_hash, mb_substr($error, 0, 600), $driver, $stage, $articleChars);

        return true;
    }
}
