<?php

namespace App\Services\Alerts;

use App\Models\CustomAnswerTerm;
use App\Services\Hermes\Hermes;
use App\Support\Prompts;
use RuntimeException;

/**
 * Traduz uma resposta escrita na entrevista para o vocabulário das notícias:
 * a IA classifica setor, tipo de ataque e produto em inglês ("Adult
 * Entertainment"), então "área adulta" sozinho nunca casaria. Usa o hermes.
 */
class CustomAnswerExpander
{
    private const TIMEOUT = 120;

    private const MAX_TERMS = 8;

    /** Parte fixa: o parse() depende deste formato. */
    public const REPLY = 'Reply with a single JSON object {"terms": [...], "codes": [...]} and nothing else — no prose, no code fence.';

    /** Tipos de resposta escrita; o pedido de cada um é editável no admin (custom.<tipo>). */
    public const KINDS = ['sector', 'region', 'attack_type', 'tech'];

    /** Desligada na aba Prompts, a resposta do cliente vale como termo do jeito que foi escrita. */
    public static function enabled(): bool
    {
        return Prompts::stageOn('respostas-escritas');
    }

    public static function system(): string
    {
        return Prompts::text('custom.system')."\n\n".self::REPLY;
    }

    /** O pedido do tipo, com a resposta do cliente no lugar de {label}. */
    public static function task(string $kind, string $label): string
    {
        return Prompts::text('custom.'.(in_array($kind, self::KINDS, true) ? $kind : 'tech'), ['label' => str_replace('"', "'", $label)]);
    }

    /** @return array{terms: list<string>, codes: list<string>} */
    public function expand(CustomAnswerTerm $answer): array
    {
        if (! Hermes::configured()) {
            throw new RuntimeException('a tradução usa o hermes, que não está configurado');
        }

        return self::parse(
            Hermes::chat(self::system(), self::task($answer->kind, $answer->label), self::TIMEOUT, extra: Hermes::light()),
            $answer->kind,
        );
    }

    /** @return array{terms: list<string>, codes: list<string>} */
    public static function parse(string $content, string $kind): array
    {
        $data = Hermes::decode($content);

        $terms = [];

        foreach ((array) ($data['terms'] ?? []) as $term) {
            $term = trim(mb_strtolower((string) $term));

            if (mb_strlen(rtrim($term, '*')) >= 3 && mb_strlen($term) <= 60) {
                $terms[$term] = true;
            }
        }

        $codes = array_values(array_unique(array_filter(
            array_map(fn ($c) => strtoupper(trim((string) $c)), (array) ($data['codes'] ?? [])),
            fn ($c) => preg_match('/^[A-Z]{2}$/', $c) === 1,
        )));

        $result = [
            'terms' => $kind === 'region' ? [] : array_slice(array_keys($terms), 0, self::MAX_TERMS),
            'codes' => $kind === 'region' ? array_slice($codes, 0, 60) : [],
        ];

        if ($result['terms'] === [] && $result['codes'] === []) {
            throw new RuntimeException('a IA não sugeriu nenhum termo');
        }

        return $result;
    }
}
