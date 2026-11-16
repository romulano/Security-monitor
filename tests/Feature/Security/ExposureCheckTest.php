<?php

namespace Tests\Feature\Security;

use App\Services\Security\CheckResult;
use App\Services\Security\SiteScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Arquivos sensíveis: uma requisição por caminho, só vale com a assinatura do conteúdo. */
class ExposureCheckTest extends TestCase
{
    use RefreshDatabase, SecurityTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSecurity();
        $this->goodDns();
        $this->fakeTls();
    }

    /** @return array<string, CheckResult> */
    private function exposures(): array
    {
        $report = app(SiteScanner::class)->scan($this->site());

        return collect($report->results)->filter(fn (CheckResult $r) => $r->id === 'file.exposed')->keyBy(fn (CheckResult $r) => $r->key())->all();
    }

    public function test_each_exposed_file_is_critical_with_its_path_and_a_clean_site_gets_one_ok(): void
    {
        $this->fakeWeb(paths: [
            '/.env' => Http::response("APP_NAME=Acme\nAPP_KEY=base64:SEGREDO-NAO-GUARDAR\nDB_PASSWORD=senha-do-banco\n"),
            '/.git/HEAD' => Http::response("ref: refs/heads/main\n"),
            '/storage/logs/laravel.log' => Http::response("[2026-10-23 10:00:00] production.ERROR: boom\n"),
        ]);

        $found = $this->exposures();

        $this->assertSame(['file.exposed|/.env', 'file.exposed|/.git/HEAD', 'file.exposed|/storage/logs/laravel.log'], array_keys($found));
        $this->assertSame('critico', $found['file.exposed|/.env']->status);
        $this->assertSame('arquivo .env acessível', $found['file.exposed|/.env']->value);

        $stored = json_encode(array_map(fn ($r) => [$r->value, $r->subject], $found));
        $this->assertStringNotContainsString('SEGREDO', $stored);
        $this->assertStringNotContainsString('senha-do-banco', $stored);

        $this->fakeWeb();
        $this->assertSame(['file.exposed|'], array_keys($this->exposures()));
        $this->assertSame('ok', $this->exposures()['file.exposed|']->status);
    }

    public function test_a_site_that_answers_200_with_its_home_for_every_path_is_not_a_leak(): void
    {
        $this->fakeHttp([
            'http://acme.com.br/' => Http::response('', 301, ['Location' => 'https://acme.com.br/']),
            'https://acme.com.br/*' => Http::response('<html><body>Aplicação de página única</body></html>', 200, $this->goodHeaders()),
        ]);

        $this->assertSame('ok', $this->exposures()['file.exposed|']->status);
    }

    public function test_a_redirect_a_403_and_a_wrong_content_are_not_exposure(): void
    {
        $this->fakeWeb(paths: [
            '/.env' => Http::response('', 302, ['Location' => '/login']),
            '/.git/HEAD' => Http::response("ref: refs/heads/main\n", 403),
            '/phpinfo.php' => Http::response('<html>página qualquer</html>'),
        ]);

        $this->assertSame('ok', $this->exposures()['file.exposed|']->status);
    }

    public function test_it_recognises_each_signature(): void
    {
        $this->fakeWeb(paths: [
            '/phpinfo.php' => Http::response('<title>phpinfo()</title>PHP Version 8.4'),
            '/server-status' => Http::response('<h1>Apache Server Status for acme.com.br</h1>'),
            '/.DS_Store' => Http::response("\x00\x00\x00\x01Bud1\x00\x00"),
            '/backup.sql' => Http::response("-- MySQL dump 10.13\nCREATE TABLE users (id int);"),
            '/backup.zip' => Http::response("PK\x03\x04\x14\x00"),
        ]);

        $this->assertSame(
            ['file.exposed|/.DS_Store', 'file.exposed|/backup.sql', 'file.exposed|/backup.zip', 'file.exposed|/phpinfo.php', 'file.exposed|/server-status'],
            collect(array_keys($this->exposures()))->sort()->values()->all(),
        );
    }

    public function test_each_path_is_requested_once_with_a_small_read_and_never_followed(): void
    {
        $this->fakeWeb();

        $this->exposures();

        $urls = Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();
        $this->assertSame(array_unique($urls), $urls, 'nenhum endereço é pedido duas vezes na varredura');
        $this->assertContains('https://acme.com.br/.env', $urls);
    }
}
