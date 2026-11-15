<?php

namespace App\Services\Alerts;

use App\Models\CustomAnswerTerm;
use App\Models\TechCatalog;

/**
 * Resposta escrita ("Não achou? Escreva aqui") que já existe no catálogo vira
 * o item do catálogo: "Intercom" escrito em Ferramentas passa a ser a
 * tecnologia Intercom, com os termos e a regra (só produto) curados, e não um
 * termo livre traduzido pela IA.
 *
 * Compara pelo nome ("Intercom"), pelas partes do nome ("Jira" de "Atlassian
 * (Jira / Confluence)", "MariaDB" de "MySQL / MariaDB"), pelo slug e, por
 * último, pelos termos de busca ("postgres", "gmail"). Sem caixa, sem acento
 * e, a partir de 4 letras, sem pontuação ("rdstation", "nodejs"). Se a mesma
 * forma serve a mais de um item, fica como resposta escrita.
 */
class CatalogMatcher
{
    /** @var list<array<string, list<int>>>|null do mais forte ao mais fraco: chave => ids */
    private ?array $levels = null;

    /** @var array<int, TechCatalog> */
    private array $techs = [];

    public function find(string $label): ?TechCatalog
    {
        $key = self::key($label);

        if ($key === '') {
            return null;
        }

        $compact = self::compact($key);

        foreach ($this->levels() as $level) {
            $ids = array_values(array_unique(array_merge(
                $level[$key] ?? [],
                strlen($compact) >= 4 ? ($level['#'.$compact] ?? []) : [],
            )));

            if (count($ids) === 1) {
                return $this->techs[$ids[0]];
            }

            if ($ids !== []) {
                return null;
            }
        }

        return null;
    }

    /** Só o catálogo curado: o que a descoberta cadastrou sozinha veio do site de outro cliente. */
    private function levels(): array
    {
        if ($this->levels !== null) {
            return $this->levels;
        }

        $levels = [[], [], []];

        foreach (TechCatalog::query()->active()->where('origin', '!=', 'discovery')->get() as $tech) {
            $this->techs[$tech->id] = $tech;
            $name = (string) $tech->name;
            $base = trim((string) preg_replace('/\s*\(.*\)\s*/', ' ', $name));
            preg_match_all('/\(([^)]*)\)/', $name, $inside);
            $parts = [];

            foreach ([$base, ...$inside[1]] as $chunk) {
                $parts = [...$parts, ...preg_split('/\s*\/\s*/', $chunk)];
            }

            $forms = [
                0 => [$name, str_replace('-', ' ', (string) $tech->slug)],
                1 => [$base, ...$parts],
                2 => array_map(fn ($term) => rtrim((string) $term, '*'), $tech->terms ?? []),
            ];

            foreach ($forms as $level => $values) {
                foreach ($values as $value) {
                    $key = self::key((string) $value);

                    if ($key === '') {
                        continue;
                    }

                    $levels[$level][$key][] = $tech->id;

                    if (strlen($compact = self::compact($key)) >= 4) {
                        $levels[$level]['#'.$compact][] = $tech->id;
                    }
                }
            }
        }

        return $this->levels = $levels;
    }

    private static function key(string $value): string
    {
        return CustomAnswerTerm::normalize($value);
    }

    private static function compact(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', $key);
    }
}
