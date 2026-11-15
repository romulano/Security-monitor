<?php

namespace Tests\Unit;

use App\Services\Monitoring\RecordedUrl;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Admin → Acessos: o endereço gravado sem o que vale como senha. */
class RecordedUrlTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function cleaned(): array
    {
        return [
            'code e state do OAuth' => ['/login/github/callback?code=abc123&state=xyz', '/login/github/callback?code=&state='],
            'link assinado' => ['/a/5/relevante?expires=1767225600&signature=9f86d0', '/a/5/relevante?expires=&signature='],
            'e-mail, e a utm fica' => ['/x?email=ana%40exemplo.com&utm_source=bluesky', '/x?email=&utm_source=bluesky'],
            'nome codificado' => ['/x?%73ignature=abc&%45MAIL=a', '/x?%73ignature=&%45MAIL='],
            'repetida, em array e em maiúsculas' => ['/x?code=1&code=2&code[]=3&CODE=4&user[password]=5', '/x?code=&code=&code[]=&CODE=&user[password]='],
            'convite do cadastro' => ['/cadastro?convite=ffeaaf5b0727202642ebcbb7d32224d7&utm_source=linkedin', '/cadastro?convite=&utm_source=linkedin'],
            'endereço inteiro' => ['https://ana:segredo@example.com/a/1/relevante?signature=abc#voto', 'https://example.com/a/1/relevante?signature='],
        ];
    }

    #[DataProvider('cleaned')]
    public function test_the_secret_values_go_away(string $uri, string $recorded): void
    {
        $this->assertSame($recorded, RecordedUrl::sanitize($uri));
        // Limpar de novo não muda nada (a migration roda de novo sem efeito).
        $this->assertSame($recorded, RecordedUrl::sanitize($recorded));
    }

    public function test_the_search_the_page_and_the_campaign_are_kept(): void
    {
        foreach (['/busca?q=union+select&page=2&filtro=cve&utm_campaign=lancamento', '/sobre', '/x?code', '/?postcode=1'] as $uri) {
            $this->assertSame($uri, RecordedUrl::sanitize($uri));
        }
    }

    public function test_the_referer_of_another_site_loses_the_query_and_ours_is_cleaned(): void
    {
        $request = Request::create('https://example.com/sobre');

        $this->assertSame('https://www.google.com/search', RecordedUrl::referer('https://www.google.com/search?q=secmon&code=1#topo', $request));
        $this->assertSame('https://example.com/a/9/nao-relevante?expires=&signature=', RecordedUrl::referer('https://example.com/a/9/nao-relevante?expires=1&signature=abc', $request));
        $this->assertSame('https://exemplo.com/', RecordedUrl::referer('https://user:pass@exemplo.com/?token=1', $request));
        $this->assertSame('sqlmap', RecordedUrl::referer('sqlmap?id=1', $request));
        $this->assertNull(RecordedUrl::referer('  ', $request));
        $this->assertNull(RecordedUrl::referer(null, $request));
    }
}
