<?php

namespace App\Services\Github;

/**
 * Mascara os segredos de um trecho de código antes de ele ser guardado,
 * mostrado ou enviado à IA: o Security Monitor nunca guarda o valor de uma chave. Fica o
 * começo (`abcd••••••••`) e um resumo de cada valor, que é o que a IA precisa
 * para separar um segredo real de um exemplo: tamanho, entropia, se é só uma
 * referência a variável de ambiente e se tem cara de exemplo.
 */
final class SecretMasker
{
    public const DOT = '•';

    /** Formatos conhecidos: mascarados em qualquer lugar do trecho e sempre com cara de real. */
    private const TOKENS = [
        'AWS' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        'GitHub' => '/\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,})\b/',
        'Slack' => '/\bxox[abprs]-[A-Za-z0-9-]{10,}/',
        'Stripe' => '/\b[rs]k_(?:live|test)_[A-Za-z0-9]{16,}\b/',
        'OpenAI' => '/\bsk-(?:proj-)?[A-Za-z0-9_\-]{20,}/',
        'Google' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
        'JWT' => '/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}/',
    ];

    /** Nomes que costumam guardar segredo, além das chaves que o cliente informou. */
    private const SECRET_NAMES = '/(pass(word|wd)?|pwd|senha|secret|token|key|auth|credential|cert|salt|signature|dsn|database[_\-]?url|conn(ection)?[_\-]?str)/i';

    /** Qualquer texto longo, aleatório e com letras e números (base64, hex, token sem nome conhecido). */
    private const RANDOM = '/(?<![A-Za-z0-9+\/=_\-])[A-Za-z0-9+\/=_\-]{24,}(?![A-Za-z0-9+\/=_\-])/';

    /** Valor que só aponta para outro lugar (variável de ambiente, cofre, template): não é o segredo. */
    private const REFERENCE = '/^(\$|%|<|\{\{)|process\.env|getenv|os\.environ|env\(|ENV\[|config\(|secrets\.|vault:|System\.getenv|Environment\./i';

    /** Cara de exemplo ou de valor falso. */
    private const PLACEHOLDER = '/x{3,}|your|seu_|sua_|changeme|change_me|example|exemplo|sample|dummy|placeholder|todo|fixme|insert|replace|redacted|\*{3,}|12345|^pass(word)?$|^senha$|test|fake|^null$|^none$|^true$|^false$|^empty$/i';

    /**
     * @param  list<string>  $keyNames  nomes de chaves do cliente (o valor ao lado deles é mascarado)
     * @param  list<string>  $prefixes  prefixos dos tokens do cliente
     * @return array{text: string, secrets: list<array{name: string, source: string, kind: string, length: int, entropy: float, looks_real: bool, masked: string}>}
     */
    public static function mask(string $text, array $keyNames = [], array $prefixes = []): array
    {
        $secrets = [];
        $keys = array_map('mb_strtolower', $keyNames);

        // Chave privada: o bloco inteiro some.
        $text = preg_replace_callback('/-----BEGIN ([A-Z ]*)PRIVATE KEY-----.*?(?:-----END \1PRIVATE KEY-----|$)/s', function ($m) use (&$secrets) {
            $secrets[] = ['name' => trim($m[1].'PRIVATE KEY'), 'source' => 'private_key', 'kind' => 'literal', 'length' => strlen($m[0]), 'entropy' => 6.0, 'looks_real' => true, 'masked' => 'chave privada'];

            return '-----BEGIN '.$m[1].'PRIVATE KEY----- '.str_repeat(self::DOT, 8).' (chave privada mascarada)';
        }, $text) ?? $text;

        // Senha dentro de URL: scheme://usuario:SENHA@host.
        $text = preg_replace_callback('~\b([a-z][a-z0-9+.\-]*://[^\s:/@]+:)([^\s@/]{1,200})(@)~i', function ($m) use (&$secrets) {
            if (preg_match(self::REFERENCE, $m[2])) {
                return $m[0];
            }

            $secrets[] = self::describe('senha na URL', 'url', $m[2]);

            return $m[1].self::hide($m[2]).$m[3];
        }, $text) ?? $text;

        foreach (self::TOKENS as $name => $pattern) {
            $text = preg_replace_callback($pattern, function ($m) use (&$secrets, $name) {
                // Formato conhecido: real, a não ser que tenha cara de exemplo (AKIAIOSFODNN7EXAMPLE).
                $secret = self::describe('token '.$name, 'token', $m[0]);
                $secrets[] = ['looks_real' => $secret['kind'] === 'literal'] + $secret;

                return self::hide($m[0], 6);
            }, $text) ?? $text;
        }

        // Tokens com o prefixo do cliente: o prefixo fica, o resto some.
        foreach (array_filter($prefixes) as $prefix) {
            $text = preg_replace_callback('/'.preg_quote($prefix, '/').'([A-Za-z0-9_\-]{6,})/i', function ($m) use (&$secrets, $prefix) {
                $secrets[] = ['looks_real' => strlen($m[1]) >= 12] + self::describe('token '.$prefix, 'prefix', $m[0]);

                return substr($m[0], 0, strlen($prefix)).str_repeat(self::DOT, 8);
            }, $text) ?? $text;
        }

        // NOME = valor, NOME: valor, "NOME": "valor", NOME => 'valor'.
        $text = preg_replace_callback(
            '/(?<name>[A-Za-z_][A-Za-z0-9_.\-]{1,80})(?<sep>["\']?\s*(?:=>|:=|[:=])\s*)(?<q>["\'`]?)(?<value>[^\s"\'`,;)\]}]{1,400})(?P=q)/',
            function ($m) use (&$secrets, $keys) {
                $value = $m['value'];
                $ownKey = in_array(mb_strtolower($m['name']), $keys, true);

                if (! $ownKey && ! preg_match(self::SECRET_NAMES, $m['name'])) {
                    return $m[0];
                }

                if (str_contains($value, self::DOT) || preg_match('/^(true|false|null|\d{1,5})$/i', $value)) {
                    return $m[0];
                }

                $secret = self::describe($m['name'], $ownKey ? 'client_key' : 'assignment', $value);
                $secrets[] = $secret;

                return $secret['kind'] === 'reference' ? $m[0] : $m['name'].$m['sep'].$m['q'].self::hide($value).$m['q'];
            },
            $text,
        ) ?? $text;

        // Última rede: texto aleatório que sobrou, seja qual for o nome (ou sem nome), também some.
        $text = preg_replace_callback(self::RANDOM, function ($m) use (&$secrets) {
            if (str_contains($m[0], self::DOT) || self::entropy($m[0]) < 3.5 || ! preg_match('/\d/', $m[0]) || ! preg_match('/[A-Za-z]/', $m[0])) {
                return $m[0];
            }

            $secrets[] = ['looks_real' => true] + self::describe('texto aleatório', 'entropy', $m[0]);

            return self::hide($m[0]);
        }, $text) ?? $text;

        return ['text' => $text, 'secrets' => array_slice($secrets, 0, 20)];
    }

    /** Resumo de um valor, sem o valor. */
    public static function describe(string $name, string $source, string $value): array
    {
        $length = mb_strlen($value);
        $entropy = round(self::entropy($value), 2);
        // Senha não precisa ser longa nem aleatória para ser real.
        $password = $source === 'url' || preg_match('/pass|senha|pwd/i', $name);
        $kind = match (true) {
            (bool) preg_match(self::REFERENCE, $value) => 'reference',
            $length < 6 || preg_match('/^(.)\1+$/', $value) || preg_match(self::PLACEHOLDER, $value) => 'placeholder',
            default => 'literal',
        };

        return [
            'name' => mb_substr($name, 0, 80),
            'source' => $source,
            'kind' => $kind,
            'length' => $length,
            'entropy' => $entropy,
            'looks_real' => $kind === 'literal' && ($password ? $length >= 8 : $length >= 12 && $entropy >= 3.0),
            'masked' => $kind === 'reference' ? mb_substr($value, 0, 60) : self::hide($value),
        ];
    }

    /** Começo do valor (só nos longos) e pontos: `abcd••••••••`. */
    public static function hide(string $value, int $keep = 4): string
    {
        $length = mb_strlen($value);
        $keep = $length >= 16 ? $keep : ($length >= 10 ? 2 : 0);

        return mb_substr($value, 0, $keep).str_repeat(self::DOT, 8);
    }

    /** Entropia de Shannon, em bits por caractere (senha aleatória: 4 ou mais; palavra: menos de 3). */
    public static function entropy(string $value): float
    {
        $length = strlen($value);

        if ($length === 0) {
            return 0.0;
        }

        $entropy = 0.0;

        foreach (count_chars($value, 1) as $count) {
            $p = $count / $length;
            $entropy -= $p * log($p, 2);
        }

        return $entropy;
    }

    /**
     * Parece o valor de uma chave, e não o nome? Usado na entrevista: o
     * cliente informa `FM_SITE_BR`, nunca `sk_test_51H...`.
     */
    public static function looksLikeSecretValue(string $input): bool
    {
        foreach (self::TOKENS as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        if (str_contains($input, 'PRIVATE KEY') || preg_match('/[:=]\s*\S{6,}/', $input)) {
            return true;
        }

        // Longo, aleatório e com letras e números misturados: valor, não nome.
        $length = strlen($input);
        $mixed = preg_match('/\d/', $input) && preg_match('/[a-z]/', $input) && preg_match('/[A-Z]/', $input);

        return $length >= 20 && self::entropy($input) >= 3.5 && ($mixed || $length >= 32);
    }
}
