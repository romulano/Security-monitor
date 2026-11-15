<?php

namespace App\Services\Keys;

use App\Services\Pentest\Executor\PentestMasker;

/**
 * As chaves num texto (o HTML, os scripts, o JSON de uma página): o tipo, a chave mascarada, o
 * nome em que ela estava, a impressão digital e quantas vezes apareceu. É o mesmo detector do
 * executor da stack de testes (`docker/pentest/executor/chaves.py`, o `achar`), aqui para a
 * varredura de chaves que roda fora da stack, nos domínios comprovados: as mesmas expressões,
 * âncoras, tetos, filtros e máscara. Os vetores de `tests/fixtures/pentest/keys/vetores.json`
 * são conferidos nos dois lados.
 *
 * O valor nunca sai daqui: só o prefixo do formato (ou o começo, nas longas), os pontos, o fim
 * (só nas de 20 caracteres ou mais) e o tamanho; a chave privada sai como "(chave privada)". A
 * impressão digital são os 32 primeiros hex do sha256 do valor (da chave privada, do tipo e do
 * corpo; da senha num endereço, do endereço sem a senha, que o hash da senha curta a entregaria).
 *
 * O texto é hostil: as expressões são lineares, cada uma só roda quando a âncora literal dela
 * está no texto, e há teto de ocorrências por tipo e de nomes de segredo olhados. O PCRE roda em
 * bytes (o texto pode não ser UTF-8); o que vira número de caracteres (o tamanho, o começo e o
 * fim da máscara) é contado depois de trocar o UTF-8 inválido por U+FFFD, como o Python decodifica.
 */
final class KeyDetector
{
    public const MAX_KEYS = 50;

    public const MAX_PER_PATTERN = 200;

    public const MAX_NAMES = 2000;

    public const MAX_COUNT = 100000;

    /** Os lugares (linha e coluna) guardados por chave numa página. */
    public const MAX_SPOTS = 5;

    private const DOTS = '••••••••';

    /** tipo => [âncoras literais, expressão com o valor no grupo 1, tamanho do prefixo do formato (nulo: o do GitHub)] */
    private const PATTERNS = [
        'aws_access_key' => [['AKIA', 'ASIA'], '~(?<![A-Za-z0-9])((?:AKIA|ASIA)[0-9A-Z]{16})(?![A-Za-z0-9])~', 4],
        'github_token' => [['ghp_', 'gho_', 'ghu_', 'ghs_', 'ghr_', 'github_pat_'],
            '~(?<![A-Za-z0-9_])(gh[pousr]_[A-Za-z0-9]{36,251}|github_pat_[A-Za-z0-9_]{22,244})(?![A-Za-z0-9_])~', null],
        'gitlab_token' => [['glpat-'], '~(?<![A-Za-z0-9_-])(glpat-[A-Za-z0-9_-]{20,64})(?![A-Za-z0-9_-])~', 6],
        'slack_webhook' => [['hooks.slack.com/services/'], '~https://hooks\.slack\.com/services/(T[A-Z0-9]{6,12}/B[A-Z0-9]{6,12}/[A-Za-z0-9]{20,40})~', 0],
        'slack_token' => [['xox'], '~(?<![A-Za-z0-9-])(xox[abprs]-[A-Za-z0-9-]{10,250})(?![A-Za-z0-9-])~', 5],
        'discord_webhook' => [['/api/webhooks/'],
            '~https://(?:(?:ptb|canary)\.)?discord(?:app)?\.com/api/webhooks/([0-9]{17,20}/[A-Za-z0-9_-]{60,80})(?![A-Za-z0-9_-])~', 0],
        'telegram_bot_token' => [[':AA'], '~(?<![A-Za-z0-9:])([0-9]{8,10}:AA[A-Za-z0-9_-]{33})(?![A-Za-z0-9_-])~', 0],
        'stripe_secret' => [['sk_live_', 'sk_test_', 'rk_live_', 'rk_test_'], '~(?<![A-Za-z0-9_])((?:sk|rk)_(?:live|test)_[A-Za-z0-9]{16,247})(?![A-Za-z0-9_])~', 8],
        'stripe_publishable' => [['pk_live_', 'pk_test_'], '~(?<![A-Za-z0-9_])(pk_(?:live|test)_[A-Za-z0-9]{16,247})(?![A-Za-z0-9_])~', 8],
        'google_api_key' => [['AIza'], '~(?<![A-Za-z0-9_-])(AIza[0-9A-Za-z_-]{35})(?![A-Za-z0-9_-])~', 4],
        'anthropic_key' => [['sk-ant-'], '~(?<![A-Za-z0-9_-])(sk-ant-[A-Za-z0-9_-]{32,200})(?![A-Za-z0-9_-])~', 7],
        // A da OpenAI: o sk- com o marcador T3BlbkFJ no meio, ou um dos prefixos novos (conferido fora da expressão).
        'openai_key' => [['sk-'], '~(?<![A-Za-z0-9_-])(sk-[A-Za-z0-9_-]{40,250})(?![A-Za-z0-9_-])~', 3],
        'sendgrid_key' => [['SG.'], '~(?<![A-Za-z0-9_-])(SG\.[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{43})(?![A-Za-z0-9_-])~', 3],
        'mailgun_key' => [['key-'], '~(?<![A-Za-z0-9_-])(key-[0-9a-f]{32})(?![A-Za-z0-9])~', 4],
        'twilio_key' => [['SK'], '~(?<![A-Za-z0-9])(SK[0-9a-f]{32})(?![A-Za-z0-9])~', 2],
        'mapbox_public' => [['pk.eyJ'], '~(?<![A-Za-z0-9])(pk\.eyJ[A-Za-z0-9_-]{20,400}\.[A-Za-z0-9_-]{20,100})(?![A-Za-z0-9_-])~', 3],
        'mapbox_secret' => [['sk.eyJ'], '~(?<![A-Za-z0-9])(sk\.eyJ[A-Za-z0-9_-]{20,400}\.[A-Za-z0-9_-]{20,100})(?![A-Za-z0-9_-])~', 3],
        // O JWT: três partes, a 2ª também um JSON; o "pk.eyJ" do Mapbox não entra (o ponto antes).
        'jwt' => [['eyJ'], '~(?<![A-Za-z0-9_.-])(eyJ[A-Za-z0-9_-]{10,2000}\.eyJ[A-Za-z0-9_-]{10,4000}\.[A-Za-z0-9_-]{10,1000})(?![A-Za-z0-9_-])~', 3],
        'sentry_dsn' => [['sentry.io'], '~https://([0-9a-f]{32})@[a-z0-9.-]{0,80}sentry\.io/[0-9]{1,20}~', 0],
        'recaptcha_site_key' => [['6L'], '~(?<![A-Za-z0-9_-])(6L[0-9A-Za-z_-]{38})(?![A-Za-z0-9_-])~', 2],
        'turnstile_site_key' => [['0x4AAAAAAA'], '~(?<![A-Za-z0-9_-])(0x4AAAAAAA[0-9A-Za-z_-]{10,30})(?![A-Za-z0-9_-])~', 10],
        'password_in_url' => [['://'], '~\b[a-z][a-z0-9+.-]{1,20}://[^\s:/@"\'`<>]{1,64}:([^\s/@"\'`<>]{1,128})@[A-Za-z0-9.-]{1,253}~', 0],
    ];

    private const PRIVATE_KEY = '~-----BEGIN ((?:[A-Z0-9]{2,10} ){0,2})PRIVATE KEY-----~';

    private const PEM_BODY = '~\A(?:\\\\[rn]|[A-Za-z0-9+/=\s])+~';

    /** O genérico: um nome de segredo e, logo depois, a atribuição de um texto entre aspas. */
    private const SECRET_NAME = '~api[_-]?key|apikey|secret|token|passw(?:or)?d|senha|credential|private[_-]?key|access[_-]?key|auth[_-]?key~i';

    private const NOT_A_SECRET_NAME = '~csrf|xsrf|nonce|captcha|sitekey|site_key|public|publishable|url|uri|endpoint|type|name|path|header|field|param|'
        .'label|placeholder|length|expir|ttl|prefix|storage|cookie|event|count|limit|input|required|valid|error|message~i';

    private const ASSIGNMENT = '~\A["\']?\s{0,3}(?:=|:|=>|:=)\s{0,3}["\'`]([A-Za-z0-9+/=_.\-]{16,200})["\'`]~';

    private const NAME_BEFORE = '~([A-Za-z_$][A-Za-z0-9_$]{0,63})["\']?\s{0,3}(?:=|:|=>|:=)\s{0,3}["\'`]?$~';

    private const REFERENCE = '~^(?:\$|%|<|\{\{)|process\.env|getenv|environ|\$\{~i';

    private const EXAMPLE = '~x{4,}|your|seu_|sua_|changeme|change_me|example|exemplo|sample|dummy|placeholder|insert|replace|redacted|'
        .'\*{3,}|0{8,}|12345678|abcdef(?:gh|12)|test_?key|fake|lorem|undefined~i';

    private const NAME = '~\A[A-Za-z_][A-Za-z0-9_.\-]{0,63}\z~';

    private const PREFIX = '~\A[A-Za-z0-9_.\-]{0,12}\z~';

    private const END = '~\A[A-Za-z0-9_\-]{4}\z~';

    /** Os tipos que o detector conhece: os 22 das expressões, a chave privada e o genérico. */
    public static function kinds(): array
    {
        return [...array_keys(self::PATTERNS), 'private_key', 'generic_secret'];
    }

    /**
     * As chaves de uma página, uma por impressão digital, na ordem em que aparecem (a chave
     * privada primeiro, depois os tipos na ordem da lista, depois o genérico).
     *
     * @return list<array{kind: string, masked: string, name: ?string, fp: string, count: int}>
     */
    public function find(string $text): array
    {
        return array_map(fn (array $one) => array_diff_key($one, ['offsets' => true]), $this->scan($text));
    }

    /**
     * Como o `find`, com onde cada chave está na página: a linha e a coluna (em bytes, a partir de 1) das primeiras
     * ocorrências. No script minificado tudo fica na linha 1: a coluna é que acha o lugar.
     *
     * @return list<array{kind: string, masked: string, name: ?string, fp: string, count: int, spots: list<array{line: int, col: int}>}>
     */
    public function findWithSpots(string $text): array
    {
        $text = self::utf8($text);

        return array_map(function (array $one) use ($text) {
            $spots = array_map(function (int $offset) use ($text) {
                $before = substr($text, 0, $offset);
                $newline = strrpos($before, "\n");

                return ['line' => substr_count($before, "\n") + 1, 'col' => $offset - ($newline === false ? -1 : $newline)];
            }, $one['offsets']);

            return array_diff_key($one, ['offsets' => true]) + ['spots' => $spots];
        }, $this->scan($text));
    }

    /** O texto como o executor decodifica: o UTF-8 inválido vira U+FFFD. */
    private static function utf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        mb_substitute_character($previous);

        return $text;
    }

    /** @return list<array{kind: string, masked: string, name: ?string, fp: string, count: int, offsets: list<int>}> */
    private function scan(string $text): array
    {
        $text = self::utf8($text);
        $found = [];

        // A chave privada: o cabeçalho e um corpo de verdade logo depois (a biblioteca que só monta o cabeçalho não conta).
        if (str_contains($text, 'PRIVATE KEY-----')) {
            foreach (self::matches(self::PRIVATE_KEY, $text) as $m) {
                $end = $m[0][1] + strlen($m[0][0]);
                $body = preg_match(self::PEM_BODY, substr($text, $end, 8000), $b) === 1 ? $b[0] : '';
                $base64 = (string) preg_replace('~\\\\[rn]|\s~', '', $body);

                if (strlen($base64) >= 64 && substr($text, $end + strlen($body), 8) === '-----END') {
                    $this->keep($found, $text, 'private_key', $m[1][0].$base64, $m[0][1], '');
                }
            }
        }

        foreach (self::PATTERNS as $kind => [$anchors, $pattern, $size]) {
            if (! array_filter($anchors, fn (string $anchor) => str_contains($text, $anchor))) {
                continue;
            }

            foreach (self::matches($pattern, $text) as $m) {
                $value = $m[1][0];

                if ($kind === 'openai_key' && (! self::openai($value) || str_starts_with($value, 'sk-ant-'))) {
                    continue;
                }

                if ($kind === 'password_in_url') {
                    if (preg_match(self::REFERENCE, $value) || preg_match(self::EXAMPLE, $value) || str_contains($value, '•') || mb_strlen($value) < 6) {
                        continue;
                    }

                    // A senha costuma ser curta: a impressão é do endereço sem ela (o usuário e o servidor), nunca da senha.
                    $whole = $m[0][0];
                    $from = $m[1][1] - $m[0][1];
                    $this->keep($found, $text, $kind, $value, $m[0][1], '', printed: substr($whole, 0, $from).substr($whole, $from + strlen($value)));

                    continue;
                }

                if (in_array($kind, ['stripe_secret', 'stripe_publishable', 'aws_access_key'], true) && preg_match(self::EXAMPLE, $value)) {
                    continue;
                }

                $this->keep($found, $text, $kind, $value, $m[0][1], self::prefix($kind, $value, $size));
            }
        }

        // O genérico: um valor aleatório entre aspas logo depois de um nome de segredo (o que os tipos acima não pegaram).
        foreach (self::matches(self::SECRET_NAME, $text, self::MAX_NAMES) as $m) {
            [$start, $end] = [$m[0][1], $m[0][1] + strlen($m[0][0])];
            $from = $start;

            while ($from > max(0, $start - 64) && self::word($text[$from - 1])) {
                $from--;
            }

            $to = $end;

            while ($to < min(strlen($text), $end + 64) && self::word($text[$to])) {
                $to++;
            }

            $name = self::name(substr($text, $from, $to - $from));

            if ($name === null || preg_match(self::NOT_A_SECRET_NAME, $name) || preg_match(self::ASSIGNMENT, substr($text, $to, 220), $a) !== 1) {
                continue;
            }

            $value = $a[1];

            if (isset($found[self::fingerprint($value)])) {   // um tipo de cima já achou (o apiKey do Google): não conta duas vezes
                continue;
            }

            if (self::entropy($value) < 3.5 || ! preg_match('~[0-9]~', $value) || ! preg_match('~[A-Za-z]~', $value) || preg_match(self::EXAMPLE, $value)
                || preg_match(self::REFERENCE, $value) || substr_count($value, '/') > 2 || substr_count($value, '.') > 2) {
                continue;
            }

            $this->keep($found, $text, 'generic_secret', $value, $from, '', name: $name);
        }

        return array_values($found);
    }

    /** O prefixo do formato (ou o começo, só nas de 16 ou mais), os pontos, o fim (só nas de 20 ou mais) e o tamanho. */
    public static function mask(string $value, string $prefix = ''): string
    {
        $length = mb_strlen($value);

        if (! preg_match(self::PREFIX, $prefix)) {
            $prefix = '';
        }

        if ($prefix === '' && $length >= 16 && preg_match(self::PREFIX, mb_substr($value, 0, 4))) {
            $prefix = mb_substr($value, 0, 4);
        }

        $end = $length >= 20 && preg_match(self::END, mb_substr($value, -4)) ? mb_substr($value, -4) : '';

        return $prefix.self::DOTS.$end.' ('.min($length, 99999).' caracteres)';
    }

    public static function fingerprint(string $value): string
    {
        return substr(hash('sha256', $value), 0, 32);
    }

    public static function entropy(string $value): float
    {
        $total = strlen($value);
        $sum = 0.0;

        foreach (count_chars($value, 1) as $count) {
            $sum -= $count / $total * log($count / $total, 2);
        }

        return $total === 0 ? 0.0 : $sum;
    }

    /**
     * Guarda a chave (ou soma uma ocorrência à que já está), até MAX_KEYS diferentes.
     *
     * @param  array<string, array{kind: string, masked: string, name: ?string, fp: string, count: int}>  $found
     */
    private function keep(array &$found, string $text, string $kind, string $value, int $start, string $prefix, ?string $name = null, ?string $printed = null): void
    {
        $fp = self::fingerprint($printed ?? ($kind === 'private_key' ? $kind.':'.$value : $value));

        if (isset($found[$fp])) {
            $found[$fp]['count'] = min($found[$fp]['count'] + 1, self::MAX_COUNT);

            if (count($found[$fp]['offsets']) < self::MAX_SPOTS) {
                $found[$fp]['offsets'][] = $start;
            }

            return;
        }

        if (count($found) >= self::MAX_KEYS) {
            return;
        }

        $found[$fp] = ['kind' => $kind, 'masked' => $kind === 'private_key' ? '(chave privada)' : self::mask($value, $prefix),
            'name' => $kind === 'private_key' ? null : ($name ?? self::nameBefore($text, $start)), 'fp' => $fp, 'count' => 1, 'offsets' => [$start]];
    }

    /**
     * As ocorrências de uma expressão, da esquerda para a direita, até o teto (como o `finditer` com o `break`): uma a uma,
     * a partir do fim da anterior, para o lookbehind ver o texto de antes e nada a mais ficar na memória.
     *
     * @return \Generator<int, array<int, array{0: string, 1: int}>>
     */
    private static function matches(string $pattern, string $text, int $max = self::MAX_PER_PATTERN): \Generator
    {
        $offset = 0;
        $length = strlen($text);

        for ($i = 0; $i < $max && $offset <= $length; $i++) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                return;
            }

            yield $m;
            $end = $m[0][1] + strlen($m[0][0]);
            $offset = $end > $m[0][1] ? $end : $end + 1;
        }
    }

    private static function prefix(string $kind, string $value, ?int $size): string
    {
        if ($kind === 'github_token') {
            return str_starts_with($value, 'github_pat_') ? 'github_pat_' : substr($value, 0, 4);
        }

        if ($kind === 'openai_key') {
            foreach (['sk-proj-', 'sk-svcacct-', 'sk-admin-'] as $prefix) {
                if (str_starts_with($value, $prefix)) {
                    return substr($prefix, 0, 12);
                }
            }
        }

        return $size ? substr($value, 0, $size) : '';
    }

    private static function openai(string $value): bool
    {
        return str_contains($value, 'T3BlbkFJ') || str_starts_with($value, 'sk-proj-') || str_starts_with($value, 'sk-svcacct-') || str_starts_with($value, 'sk-admin-');
    }

    /** Uma letra ou dígito ASCII, ou `_`, `$`, `-`: o que continua um nome de segredo. */
    private static function word(string $byte): bool
    {
        return ctype_alnum($byte) || $byte === '_' || $byte === '$' || $byte === '-';
    }

    /** O nome que pode sair: no formato e sem nada que a máscara mudaria (um segredo nunca sai como "nome"). */
    private static function name(string $name): ?string
    {
        $name = ltrim($name, '$');

        return preg_match(self::NAME, $name) && PentestMasker::mask($name, 80) === $name ? $name : null;
    }

    /** O nome em que o valor estava (`STRIPE_KEY = "pk_live_..."`, `apiKey: "AIza..."`), se ele vem logo antes. */
    private static function nameBefore(string $text, int $start): ?string
    {
        $from = max(0, $start - 80);

        return preg_match(self::NAME_BEFORE, substr($text, $from, $start - $from), $m) ? self::name($m[1]) : null;
    }
}
