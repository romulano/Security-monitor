<?php

namespace App\Services\Admin;

use App\Services\Alerts\Discovery\StackDetector;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Os campos de texto do catálogo e das perguntas (um item por linha) ↔ os
 * arrays gravados. Linha que não segue o formato vira erro de validação.
 */
class CatalogText
{
    private const DETECT_TYPES = StackDetector::TYPES;

    /** Termos de busca, um por linha ou separados por vírgula: minúsculos, sem vazio nem só curinga. */
    public static function parseTerms(string $text): array
    {
        return collect(preg_split('/[\n,]+/', $text))
            ->map(fn ($t) => trim(mb_strtolower($t)))
            ->filter(fn ($t) => $t !== '' && trim($t, '*') !== '')
            ->unique()->values()->all();
    }

    /** Linhas "tipo:nome:padrão" ↔ regras [{type, name?, pattern}]. */
    public static function detectToText(array $rules): string
    {
        return collect($rules)->map(fn ($r) => $r['type'] === 'header'
            ? "header:{$r['name']}:{$r['pattern']}"
            : "{$r['type']}:{$r['pattern']}")->implode("\n");
    }

    /** @throws ValidationException em `detect`, com o número da linha */
    public static function parseDetect(string $text): array
    {
        $rules = [];

        foreach (array_filter(array_map('trim', explode("\n", $text))) as $n => $line) {
            $parts = explode(':', $line, 2);
            $type = strtolower($parts[0]);
            $rest = $parts[1] ?? '';

            if (! in_array($type, self::DETECT_TYPES, true) || $rest === '') {
                throw ValidationException::withMessages(['detect' => 'Linha '.($n + 1).': use tipo:padrão (tipos: '.implode(', ', self::DETECT_TYPES).'); para header, header:nome:padrão.']);
            }

            if ($type === 'header') {
                [$name, $pattern] = array_pad(explode(':', $rest, 2), 2, '');

                if ($pattern === '') {
                    throw ValidationException::withMessages(['detect' => 'Linha '.($n + 1).': header precisa de nome e padrão (header:server:nginx).']);
                }

                $rule = ['type' => 'header', 'name' => strtolower(trim($name)), 'pattern' => trim($pattern)];
            } else {
                $rule = ['type' => $type, 'pattern' => trim($rest)];
            }

            if (str_starts_with($rule['pattern'], 're:') && @preg_match('/'.str_replace('/', '\/', substr($rule['pattern'], 3)).'/i', '') === false) {
                throw ValidationException::withMessages(['detect' => 'Linha '.($n + 1).': regex inválida.']);
            }

            $rules[] = $rule;
        }

        return $rules;
    }

    /** Linhas "valor|rótulo|descrição" ↔ opções [{value, label, desc?}]. */
    public static function optionsToText(array $options): string
    {
        return collect($options)->map(fn ($o) => implode('|', array_filter([
            $o['value'] ?? '', $o['label'] ?? '', $o['desc'] ?? null,
        ], fn ($v) => $v !== null)))->implode("\n");
    }

    /** O valor vira slug; sem rótulo, o rótulo é o próprio valor. Linhas sem valor ficam de fora. */
    public static function parseOptions(string $text): array
    {
        $options = [];

        foreach (array_filter(array_map('trim', explode("\n", $text))) as $line) {
            [$value, $label, $desc] = array_pad(array_map('trim', explode('|', $line, 3)), 3, null);
            $value = Str::slug($value, '_');

            if ($value !== '') {
                $options[] = array_filter(['value' => $value, 'label' => $label ?: $value, 'desc' => $desc ?: null]);
            }
        }

        return $options;
    }
}
