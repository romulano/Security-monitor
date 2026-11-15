<?php

namespace Tests\Unit\Keys;

use App\Services\Keys\KeyDetector;
use App\Services\Pentest\Keys\KeyKinds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O detector de chaves do app é o do executor (`docker/pentest/executor/chaves.py`): os vetores
 * de `tests/fixtures/pentest/keys/vetores.json` saem do Python e têm de dar o mesmo aqui, campo a
 * campo (o tipo, a chave mascarada, o nome, a impressão digital e a contagem), na mesma ordem.
 */
class KeyDetectorTest extends TestCase
{
    /** @return array<string, array{0: string, 1: list<array<string, mixed>>}> */
    public static function vectors(): array
    {
        $file = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/pentest/keys/vetores.json'), true, 512, JSON_THROW_ON_ERROR);

        return collect($file['vectors'])->mapWithKeys(fn (array $v) => [$v['name'] => [$v['text'], $v['expected']]])->all();
    }

    /** @param  list<array<string, mixed>>  $expected */
    #[DataProvider('vectors')]
    public function test_the_same_keys_as_the_executor(string $text, array $expected): void
    {
        $this->assertSame($expected, (new KeyDetector)->find($text));
    }

    public function test_the_vectors_cover_every_kind_and_the_kinds_are_the_apps(): void
    {
        $seen = collect(self::vectors())->flatMap(fn (array $v) => array_column($v[1], 'kind'))->unique()->sort()->values()->all();
        $kinds = KeyDetector::kinds();
        sort($kinds);

        $this->assertSame($kinds, $seen);
        $app = array_keys(KeyKinds::KINDS);
        sort($app);
        $this->assertSame($app, $kinds);
    }

    public function test_hostile_text_stays_in_time_and_under_the_cap(): void
    {
        $detector = new KeyDetector;

        foreach (['sk-'.str_repeat('a', 39).' ', 'eyJ'.str_repeat('a', 20).'.', 'apiKey: "', 'AKIA', '://u:', '-----BEGIN RSA PRIVATE KEY-----', 'xoxb-'] as $piece) {
            $text = str_repeat($piece, intdiv(1048576, strlen($piece)));
            $start = microtime(true);
            $found = $detector->find($text);
            $this->assertLessThan(5, microtime(true) - $start, $piece);
            $this->assertLessThanOrEqual(KeyDetector::MAX_KEYS, count($found), $piece);
        }

        $many = implode(' ', array_map(fn (int $i) => '"AKIA'.sprintf('%04d', $i).'ZQXWVUTSRPNM"', range(0, 499)));
        $this->assertCount(KeyDetector::MAX_KEYS, $detector->find($many));
    }

    public function test_each_key_says_where_it_is_by_line_and_column(): void
    {
        $text = "<html>\n<head>\n  <script>var k = \"AKIAZ3MSJV4WWNKWW7XQ\";</script>\n</head>\n<body>AKIAZ3MSJV4WWNKWW7XQ</body>";
        $found = (new KeyDetector)->findWithSpots($text);

        $this->assertSame([['line' => 3, 'col' => 20], ['line' => 5, 'col' => 7]], $found[0]['spots']);
        $this->assertSame(2, $found[0]['count']);
        // O `find` (o do executor) continua sem os lugares.
        $this->assertArrayNotHasKey('spots', (new KeyDetector)->find($text)[0]);
        $this->assertArrayNotHasKey('offsets', (new KeyDetector)->find($text)[0]);

        // No minificado, tudo na linha 1: a coluna acha o lugar; até 5 lugares por chave.
        $minified = str_repeat('a=1;', 100).implode(';', array_fill(0, 7, 'x="AKIAZ3MSJV4WWNKWW7XQ"'));
        $spots = (new KeyDetector)->findWithSpots($minified)[0]['spots'];
        $this->assertCount(5, $spots);
        $this->assertSame(['line' => 1, 'col' => 404], $spots[0]);
    }

    public function test_invalid_utf8_is_read_like_the_executor_decodes_it(): void
    {
        $found = (new KeyDetector)->find("\xff\xfe var k = \"AKIAZ3MSJV4WWNKWW7XQ\"; \xc3");

        $this->assertSame([['kind' => 'aws_access_key', 'masked' => 'AKIA••••••••W7XQ (20 caracteres)', 'name' => 'k', 'fp' => KeyDetector::fingerprint('AKIAZ3MSJV4WWNKWW7XQ'), 'count' => 1]], $found);
    }
}
