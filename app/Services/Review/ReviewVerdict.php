<?php

namespace App\Services\Review;

use App\Services\Hermes\Hermes;
use RuntimeException;

/**
 * O parecer do revisor, validado: `aprovado`, ou `ajustar` com o escopo
 * (`parte`: só os campos citados; `tudo`: o rascunho inteiro) e a lista de
 * problemas, cada um com o campo, o trecho e a instrução.
 *
 * Resposta fora do formato é erro (RuntimeException): quem chama trata como
 * revisão que não aconteceu, e a matéria segue.
 */
final class ReviewVerdict
{
    public const APPROVED = 'aprovado';

    public const ADJUST = 'ajustar';

    public const PART = 'parte';

    public const ALL = 'tudo';

    /** Problemas lidos por parecer; o resto é ignorado. */
    private const MAX_PROBLEMS = 10;

    /** @param  list<array{field: ?string, excerpt: string, instruction: string}>  $problems */
    public function __construct(
        public readonly string $verdict,
        public readonly ?string $scope = null,
        public readonly array $problems = [],
        public readonly string $note = '',
    ) {}

    public function approved(): bool
    {
        return $this->verdict === self::APPROVED;
    }

    /** Os campos que a refação em `parte` reescreve. @return list<string> */
    public function fields(): array
    {
        return array_values(array_unique(array_filter(array_column($this->problems, 'field'))));
    }

    /**
     * @param  list<string>  $fields  os campos que o revisor pode citar
     *
     * @throws RuntimeException resposta sem JSON, com parecer ou escopo fora da lista, ou `ajustar` sem problema
     */
    public static function parse(string $content, array $fields): self
    {
        $data = Hermes::object($content) ?? throw new RuntimeException('o revisor não devolveu JSON');
        $verdict = is_string($data['verdict'] ?? null) ? mb_strtolower(trim($data['verdict'])) : '';

        if ($verdict === self::APPROVED) {
            return new self(self::APPROVED, note: self::text($data['note'] ?? null, 300));
        }

        if ($verdict !== self::ADJUST) {
            throw new RuntimeException('o revisor deu um parecer fora da lista: '.mb_substr(json_encode($data['verdict'] ?? null), 0, 40));
        }

        $scope = is_string($data['scope'] ?? null) ? mb_strtolower(trim($data['scope'])) : '';

        if (! in_array($scope, [self::PART, self::ALL], true)) {
            throw new RuntimeException('o revisor pediu ajuste sem escopo válido (parte ou tudo)');
        }

        $problems = [];

        foreach (array_slice(is_array($data['problems'] ?? null) ? $data['problems'] : [], 0, self::MAX_PROBLEMS) as $problem) {
            $instruction = self::text(is_array($problem) ? ($problem['instruction'] ?? null) : null, 600);

            if ($instruction === '') {
                continue;
            }

            $field = is_string($problem['field'] ?? null) ? mb_strtolower(trim($problem['field'])) : '';

            $problems[] = [
                // Campo que não existe no rascunho vale como problema do texto todo.
                'field' => in_array($field, $fields, true) ? $field : null,
                'excerpt' => self::text($problem['excerpt'] ?? null, 300),
                'instruction' => $instruction,
            ];
        }

        if ($problems === []) {
            throw new RuntimeException('o revisor pediu ajuste sem dizer o que ajustar');
        }

        // Em `parte`, a refação só mexe nos campos citados: sem nenhum, ou com um
        // problema que não diz o campo, só refazendo tudo.
        if ($scope === self::PART && in_array(null, array_column($problems, 'field'), true)) {
            $scope = self::ALL;
        }

        return new self(self::ADJUST, $scope, $problems, self::text($data['note'] ?? null, 300));
    }

    /** Uma linha de texto da IA; o que não é texto fica vazio. */
    private static function text(mixed $value, int $max): string
    {
        return is_string($value) ? Hermes::sentence($value, $max) : '';
    }

    /** @return array{verdict: string, scope: ?string, problems: list<array{field: ?string, excerpt: string, instruction: string}>, note: string} */
    public function toArray(): array
    {
        return ['verdict' => $this->verdict, 'scope' => $this->scope, 'problems' => $this->problems, 'note' => $this->note];
    }
}
