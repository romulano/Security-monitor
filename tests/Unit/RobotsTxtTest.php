<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** O robots.txt tira as buscas do alcance dos robôs, deixa o resto do site aberto e aponta o sitemap. */
class RobotsTxtTest extends TestCase
{
    /** @return list<string> os caminhos em Disallow */
    private function disallowed(): array
    {
        preg_match_all('/^Disallow:\s*(\S*)\s*$/mi', (string) file_get_contents(dirname(__DIR__, 2).'/public/robots.txt'), $m);

        return $m[1];
    }

    public function test_search_combinations_and_the_json_api_are_off_limits(): void
    {
        foreach (['/?q=', '/*?q=', '/*&q=', '/api/', '/idioma/'] as $path) {
            $this->assertContains($path, $this->disallowed(), $path);
        }
    }

    public function test_the_robots_find_the_sitemap_and_it_is_not_blocked(): void
    {
        $robots = (string) file_get_contents(dirname(__DIR__, 2).'/public/robots.txt');

        // Absoluto, como o protocolo pede; o arquivo é estático, então é o endereço de produção.
        $this->assertMatchesRegularExpression('#^Sitemap: https://example\.com/sitemap\.xml$#m', $robots);

        foreach ($this->disallowed() as $rule) {
            $regex = '#^'.str_replace('\*', '.*', preg_quote($rule, '#')).'#';
            $this->assertDoesNotMatchRegularExpression($regex, '/sitemap.xml', "{$rule} bloquearia o sitemap");
            $this->assertDoesNotMatchRegularExpression($regex, '/sitemap-2.xml', "{$rule} bloquearia uma parte do sitemap");
        }
    }

    public function test_the_feed_pagination_and_the_stories_stay_open(): void
    {
        $rules = $this->disallowed();

        $this->assertNotContains('/', $rules, 'Disallow: / tiraria o site inteiro do Google');
        $this->assertNotContains('', array_filter($rules, fn ($r) => $r === ''), 'Disallow vazio liberava tudo, inclusive as buscas');

        foreach ($rules as $rule) {
            $regex = '#^'.str_replace('\*', '.*', preg_quote($rule, '#')).'#';

            foreach (['/', '/?page=2', '/materia/cad2fa7b-709a-42fc-839d-0a1ffa5ef7d9', '/sobre', '/dashboard', '/termos', '/privacidade'] as $url) {
                $this->assertDoesNotMatchRegularExpression($regex, $url, "{$rule} bloquearia {$url}");
            }
        }
    }
}
