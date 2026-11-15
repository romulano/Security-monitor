<?php

namespace App\Services\Lookalike;

use App\Support\RegistrableDomain;

/**
 * Nomes que um golpista registraria para se passar pelo domínio do cliente,
 * no estilo do dnstwist: erro de digitação, letra parecida, outra terminação,
 * palavra de golpe ("-login", "-pix"), letra de outro alfabeto.
 *
 * A ordem importa: quando passa do teto, ficam os mais perigosos (os que
 * enganam a olho nu, nas terminações mais usadas) e saem as combinações mais
 * distantes (erro de digitação nas terminações menos prováveis).
 */
final class VariantGenerator
{
    public const LABELS = [
        'terminacao' => 'outra terminação',
        'parecida' => 'letra parecida',
        'outro-alfabeto' => 'letra de outro alfabeto',
        'acento' => 'letra com acento',
        'hifen' => 'com hífen',
        'omissao' => 'falta uma letra',
        'repeticao' => 'letra repetida',
        'troca' => 'letras trocadas de lugar',
        'vizinha' => 'tecla vizinha',
        'vogal' => 'vogal trocada',
        'acrescimo' => 'letra a mais',
        'palavra' => 'com palavra de golpe',
        'marca' => 'nome da marca',
        'subdominio' => 'imita um subdomínio seu',
    ];

    /** Rótulos de subdomínio que não lembram a marca sozinhos (E4b): não viram semente nem contam no teto de sementes. */
    public const GENERIC_SUBDOMAINS = ['www', 'mail', 'smtp', 'mx', 'ns1', 'ns2', 'ns3', 'autodiscover', 'autoconfig', '_dmarc', '_domainkey',
        'webmail', 'cpanel', 'whm', 'ftp', 'imap', 'pop', 'pop3', 'dns', 'm', 'email', 'cdn', 'static', 'api', 'www2'];

    /** As terminações das variações compactas de subdomínio (as primeiras de SUFFIXES, as mais usadas em golpe). */
    public const SUBDOMAIN_SUFFIXES = 10;

    /**
     * Terminações conferidas, da mais à menos relevante: as 30 mais usadas em golpe no Brasil;
     * depois as que 2 ou 3 de 3 marcas grandes (nubank, itaú, mercadolivre) registraram;
     * depois 25 registradas por uma delas e abertas ao público. O nome exato vai em todas; o erro
     * de digitação, também (depois dos que valem em .com e .com.br, para o teto cortar por último).
     */
    public const SUFFIXES = [
        'com', 'com.br', 'net', 'net.br', 'org', 'org.br', 'app', 'online', 'site', 'store', 'shop', 'xyz', 'top', 'info',
        'io', 'co', 'me', 'live', 'click', 'link', 'vip', 'club', 'pro', 'digital', 'tech', 'website', 'space', 'fun', 'cc', 'biz',
        'ca', 'ch', 'es', 'eu', 'it', 'pl', 'si', 'us', 'uy',
        'ai', 'ar', 'bar', 'be', 'bet', 'blog', 'cam', 'cl', 'cn', 'cz', 'de', 'design', 'dev', 'dk', 'do', 'ec', 'finance', 'gr', 'gt', 'help', 'hn',
        'in', 'jp', 'kids', 'lat', 'life', 'mx', 'pa', 'pay', 'pe', 'pt', 'se', 'services', 'sk', 'uk', 'world',
        'cloud', 'support', 'buzz', 'cash', 'money', 'chat', 'page', 'team', 'global', 'company', 'contact', 'ru', 'nl', 'fr', 'hk', 'ie', 'fi', 'kr',
        'tv', 'one', 'mobi', 'asia', 'at', 'ltd', 'id',
    ];

    /** Palavras que golpe costuma pendurar no nome da marca. */
    public const KEYWORDS = [
        'login', 'entrar', 'conta', 'minhaconta', 'acesso', 'app', 'suporte', 'sac', 'atendimento', 'ajuda', 'seguro', 'seguranca',
        'verificar', 'verificacao', 'validar', 'confirmar', 'cadastro', 'pagamento', 'pix', 'boleto', 'fatura', 'premio', 'promo',
        'bonus', 'oficial', 'online', 'portal', 'cliente', 'br',
    ];

    /** Trocas que enganam a olho nu (em fonte comum, na barra do navegador). */
    private const LOOKALIKE_ASCII = [
        'o' => ['0'], '0' => ['o'], 'l' => ['1', 'i'], 'i' => ['1', 'l'], '1' => ['l', 'i'],
        'm' => ['rn', 'nn'], 'rn' => ['m'], 'w' => ['vv'], 'vv' => ['w'], 'd' => ['cl'], 'cl' => ['d'],
        'n' => ['m', 'r'], 'u' => ['v'], 'v' => ['u'], 'g' => ['q'], 'q' => ['g'], 'e' => ['c'], 'c' => ['e'], 's' => ['5'], 'b' => ['6'],
    ];

    /** Cirílico que é idêntico ao latino (só nas terminações que aceitam esse alfabeto). */
    private const CYRILLIC = ['a' => 'а', 'c' => 'с', 'e' => 'е', 'o' => 'о', 'p' => 'р', 'x' => 'х', 'y' => 'у', 'i' => 'і', 's' => 'ѕ'];

    private const ACCENTS = ['a' => ['á', 'à', 'â', 'ã'], 'e' => ['é', 'ê'], 'i' => ['í'], 'o' => ['ó', 'ô', 'õ'], 'u' => ['ú'], 'c' => ['ç']];

    /** O .br aceita só letras latinas com acento; o cirílico vai nas genéricas. */
    private const CYRILLIC_SUFFIXES = ['com', 'net', 'org'];

    private const KEYBOARD = [
        'q' => 'wa', 'w' => 'qeas', 'e' => 'wrsd', 'r' => 'etdf', 't' => 'ryfg', 'y' => 'tugh', 'u' => 'yihj', 'i' => 'uojk', 'o' => 'ipkl', 'p' => 'ol',
        'a' => 'qwsz', 's' => 'awedxz', 'd' => 'serfcx', 'f' => 'drtgvc', 'g' => 'ftyhbv', 'h' => 'gyujnb', 'j' => 'huikmn', 'k' => 'jiolm', 'l' => 'kop',
        'z' => 'asx', 'x' => 'zsdc', 'c' => 'xdfv', 'v' => 'cfgb', 'b' => 'vghn', 'n' => 'bhjm', 'm' => 'njk',
        '1' => '2q', '2' => '13qw', '3' => '24we', '4' => '35er', '5' => '46rt', '6' => '57ty', '7' => '68yu', '8' => '79ui', '9' => '80io', '0' => '9op',
    ];

    private const VOWELS = ['a', 'e', 'i', 'o', 'u'];

    /**
     * @param  list<string>  $brands  outros nomes da marca ("Fatal Model", "AcmeConta"), viram rótulos também
     * @param  list<string>  $exclude  domínios do próprio cliente, que nunca entram
     * @return array<string, string> host => técnica (a primeira que gerou o nome)
     */
    public function generate(string $domain, array $brands = [], array $exclude = [], ?int $max = null): array
    {
        $domain = strtolower($domain);
        $registrable = RegistrableDomain::of($domain) ?? $domain;
        $label = RegistrableDomain::label($registrable);
        $suffix = substr($registrable, strlen($label) + 1);
        $max ??= (int) config('secmon.lookalikes.max_variants', 900);

        $excluded = array_flip(array_map('strtolower', array_merge([$registrable], $exclude)));
        $out = [];
        $add = function (string $candidateLabel, string $candidateSuffix, string $technique) use (&$out, $excluded, $label) {
            $host = $this->toAscii($candidateLabel.'.'.$candidateSuffix);

            if ($host === null || isset($excluded[$host]) || isset($out[$host]) || $candidateLabel === '') {
                return;
            }

            // Rótulo curto demais deixa de lembrar a marca ("fm.com" não engana ninguém).
            if (mb_strlen($candidateLabel) < max(3, mb_strlen($label) - 3)) {
                return;
            }

            $out[$host] = $technique;
        };

        // Variações de digitação valem na terminação original e nas duas mais comuns.
        $typoSuffixes = array_values(array_unique([$suffix, 'com', 'com.br']));

        // 1. O nome exato em outra terminação e as outras grafias da marca.
        foreach (self::SUFFIXES as $s) {
            $add($label, $s, 'terminacao');
        }

        foreach ($this->brandLabels($brands, $label) as $brandLabel) {
            foreach (['com', 'com.br', 'net', 'app', 'online'] as $s) {
                $add($brandLabel, $s, 'marca');
            }
        }

        // 2. O que engana a olho nu.
        foreach ($typoSuffixes as $s) {
            foreach ($this->lookalikeAscii($label) as $v) {
                $add($v, $s, 'parecida');
            }
        }

        foreach ($this->homoglyphs($label, self::ACCENTS) as $v) {
            foreach ($typoSuffixes as $s) {
                $add($v, $s, 'acento');
            }
        }

        foreach ($this->homoglyphs($label, array_map(fn ($c) => [$c], self::CYRILLIC)) as $v) {
            foreach (array_intersect($typoSuffixes, self::CYRILLIC_SUFFIXES) ?: ['com'] as $s) {
                $add($v, $s, 'outro-alfabeto');
            }
        }

        // 3. Palavra de golpe pendurada no nome.
        foreach (self::KEYWORDS as $kw) {
            foreach (['com', 'com.br'] as $s) {
                $add($label.'-'.$kw, $s, 'palavra');
                $add($label.$kw, $s, 'palavra');
                $add($kw.'-'.$label, $s, 'palavra');
                $add($kw.$label, $s, 'palavra');
            }
        }

        // 4. Erros de digitação (na terminação do cliente, em .com e em .com.br).
        $typing = function (string $s) use ($label, $add) {
            foreach ($this->hyphenated($label) as $v) {
                $add($v, $s, 'hifen');
            }
            foreach ($this->omissions($label) as $v) {
                $add($v, $s, 'omissao');
            }
            foreach ($this->transpositions($label) as $v) {
                $add($v, $s, 'troca');
            }
            foreach ($this->repetitions($label) as $v) {
                $add($v, $s, 'repeticao');
            }
            foreach ($this->vowelSwaps($label) as $v) {
                $add($v, $s, 'vogal');
            }
            foreach ($this->keyboard($label) as $v) {
                $add($v, $s, 'vizinha');
            }
            foreach (range('a', 'z') as $letter) {
                $add($label.$letter, $s, 'acrescimo');
            }
        };

        foreach ($typoSuffixes as $s) {
            $typing($s);
        }

        // 5. As mesmas trocas e erros nas outras terminações, da mais à menos relevante. Vêm por
        // último: se o teto cortar, saem as terminações menos prováveis, nunca as de cima.
        foreach (array_diff(self::SUFFIXES, $typoSuffixes) as $s) {
            foreach ($this->lookalikeAscii($label) as $v) {
                $add($v, $s, 'parecida');
            }
            foreach ($this->homoglyphs($label, self::ACCENTS) as $v) {
                $add($v, $s, 'acento');
            }
            if (in_array($s, self::CYRILLIC_SUFFIXES, true)) {
                foreach ($this->homoglyphs($label, array_map(fn ($c) => [$c], self::CYRILLIC)) as $v) {
                    $add($v, $s, 'outro-alfabeto');
                }
            }
            $typing($s);
        }

        return array_slice($out, 0, $max, true);
    }

    /** O subdomínio vira semente? O 1º rótulo à esquerda não pode ser genérico (www, mail…). */
    public static function isSeed(string $sub): bool
    {
        return ! in_array(explode('.', strtolower($sub))[0], self::GENERIC_SUBDOMAINS, true);
    }

    /**
     * As variações compactas de um subdomínio (E4b, DESENHO-5.3 §5.3): com o 1º rótulo L ("login") e a marca B
     * ("empresa"), L-B, LB, B-L e BL (e os dois rótulos com hífen, em "login.app.empresa") nas 10 terminações mais
     * usadas em golpe: ~40 nomes por semente, sem erro de digitação. Genérico (www, mail…) não gera nada.
     *
     * @param  list<string>  $exclude  os nomes da própria empresa
     * @return array<string, string> host => 'subdominio'
     */
    public function generateForSubdomain(string $sub, string $registrable, array $exclude = []): array
    {
        $registrable = strtolower($registrable);
        $sub = strtolower($sub);

        if (! self::isSeed($sub) || ! str_ends_with($sub, '.'.$registrable)) {
            return [];
        }

        $brand = RegistrableDomain::label($registrable);
        $parts = explode('.', substr($sub, 0, -strlen($registrable) - 1));
        $first = $parts[0];
        $labels = array_unique(array_filter([
            $first.'-'.$brand, $first.$brand, $brand.'-'.$first, $brand.$first,
            count($parts) > 1 ? implode('-', array_slice($parts, 0, 2)).'-'.$brand : null,
        ], fn (?string $label) => $label !== null && preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) === 1));
        $excluded = array_flip(array_map('strtolower', array_merge([$registrable], $exclude)));
        $out = [];

        foreach (array_slice(self::SUFFIXES, 0, self::SUBDOMAIN_SUFFIXES) as $suffix) {
            foreach ($labels as $label) {
                $host = $label.'.'.$suffix;

                if (! isset($excluded[$host])) {
                    $out[$host] = 'subdominio';
                }
            }
        }

        return $out;
    }

    /**
     * Agrupa por terminação (E4b): uma terminação muda (o registro dela lento) cai em poucos lotes, e não em todos —
     * na ordem rótulo × terminação, cada lote de 100 levaria ~10 nomes mudos e estouraria o teto de 90 s (lab/carga-e4b).
     *
     * @param  list<string>  $hosts
     * @return list<string>
     */
    public static function bySuffix(array $hosts): array
    {
        usort($hosts, fn (string $a, string $b) => [substr($a, strpos($a, '.')), $a] <=> [substr($b, strpos($b, '.')), $b]);

        return $hosts;
    }

    /** Rótulo e terminação do domínio registrável: ["fatalmodel", "com"]. */
    public static function split(string $domain): array
    {
        $registrable = RegistrableDomain::of(strtolower($domain)) ?? strtolower($domain);
        $label = RegistrableDomain::label($registrable);

        return [$label, substr($registrable, strlen($label) + 1)];
    }

    /** "Fatal Model" → ["fatalmodel", "fatal-model"]; nomes que já são o rótulo saem. */
    private function brandLabels(array $brands, string $label): array
    {
        $labels = [];

        foreach ($brands as $brand) {
            $brand = mb_strtolower(trim((string) $brand));
            $brand = preg_replace('/\.(com|net|org|app|io|online|site|store|shop)(\.br)?$|\.com\.br$/', '', $brand);
            $ascii = $this->stripAccents($brand);
            $words = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY);

            if ($words === []) {
                continue;
            }

            $labels[] = implode('', $words);
            $labels[] = implode('-', $words);
        }

        return array_values(array_diff(array_unique($labels), [$label]));
    }

    private function lookalikeAscii(string $label): array
    {
        $out = [];

        foreach (self::LOOKALIKE_ASCII as $from => $tos) {
            $offset = 0;

            while (($pos = strpos($label, (string) $from, $offset)) !== false) {
                foreach ($tos as $to) {
                    $out[] = substr($label, 0, $pos).$to.substr($label, $pos + strlen((string) $from));
                }
                $offset = $pos + 1;
            }
        }

        return $out;
    }

    /** Uma letra por vez trocada pela parecida de outro conjunto (acento ou cirílico). */
    private function homoglyphs(string $label, array $map): array
    {
        $out = [];
        $chars = mb_str_split($label);

        foreach ($chars as $i => $char) {
            foreach ($map[$char] ?? [] as $glyph) {
                $copy = $chars;
                $copy[$i] = $glyph;
                $out[] = implode('', $copy);
            }
        }

        return $out;
    }

    private function hyphenated(string $label): array
    {
        $out = [];

        for ($i = 1; $i < strlen($label); $i++) {
            if ($label[$i - 1] !== '-' && $label[$i] !== '-') {
                $out[] = substr($label, 0, $i).'-'.substr($label, $i);
            }
        }

        // O hífen que o cliente já usa, tirado: "acme-pay" → "acmepay".
        if (str_contains($label, '-')) {
            $out[] = str_replace('-', '', $label);
        }

        return $out;
    }

    private function omissions(string $label): array
    {
        $out = [];

        for ($i = 0; $i < strlen($label); $i++) {
            $out[] = substr($label, 0, $i).substr($label, $i + 1);
        }

        return $out;
    }

    private function transpositions(string $label): array
    {
        $out = [];

        for ($i = 0; $i < strlen($label) - 1; $i++) {
            if ($label[$i] !== $label[$i + 1]) {
                $out[] = substr($label, 0, $i).$label[$i + 1].$label[$i].substr($label, $i + 2);
            }
        }

        return $out;
    }

    private function repetitions(string $label): array
    {
        $out = [];

        for ($i = 0; $i < strlen($label); $i++) {
            if (ctype_alnum($label[$i])) {
                $out[] = substr($label, 0, $i).$label[$i].substr($label, $i);
            }
        }

        return $out;
    }

    private function vowelSwaps(string $label): array
    {
        $out = [];

        for ($i = 0; $i < strlen($label); $i++) {
            if (in_array($label[$i], self::VOWELS, true)) {
                foreach (self::VOWELS as $vowel) {
                    if ($vowel !== $label[$i]) {
                        $out[] = substr($label, 0, $i).$vowel.substr($label, $i + 1);
                    }
                }
            }
        }

        return $out;
    }

    private function keyboard(string $label): array
    {
        $out = [];

        for ($i = 0; $i < strlen($label); $i++) {
            foreach (str_split(self::KEYBOARD[$label[$i]] ?? '') as $near) {
                $out[] = substr($label, 0, $i).$near.substr($label, $i + 1);
            }
        }

        return $out;
    }

    /** Nome válido em ASCII (punycode para os com acento ou cirílico); nulo se não pode existir. */
    private function toAscii(string $host): ?string
    {
        if (preg_match('/[^\x20-\x7e]/', $host)) {
            if (! function_exists('idn_to_ascii')) {
                return null;
            }

            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: null;

            if ($host === null) {
                return null;
            }
        }

        foreach (explode('.', $host) as $part) {
            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $part) || (strlen($part) > 3 && substr($part, 2, 2) === '--' && ! str_starts_with($part, 'xn--'))) {
                return null;
            }
        }

        return $host;
    }

    private function stripAccents(string $value): string
    {
        return strtr($value, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
    }
}
