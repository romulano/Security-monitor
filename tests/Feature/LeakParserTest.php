<?php

namespace Tests\Feature;

use App\Services\Leaks\CredentialLineParser;
use App\Services\Leaks\FreeMailList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** O parser dos dumps: acha o endereço, o login e a senha sem contar posições — e recusa o que não prova. */
class LeakParserTest extends TestCase
{
    use RefreshDatabase;

    private function parse(string $line): array
    {
        return app(CredentialLineParser::class)->parse($line);
    }

    /** @return array<string,array{0:string,1:string,2:string,3:string}> */
    public static function ordens(): array
    {
        return [
            'país + url:login:senha' => [
                'PE https://login.live.com/oauth20_authorize.srf:ana@exemplocorp.com:Senha@000111',
                'ana@exemplocorp.com', 'Senha@000111', 'login.live.com',
            ],
            'url:login:senha sem país' => [
                'https://cassino.exemplo.com/m/login:jogador123:abc123',
                'jogador123', 'abc123', 'cassino.exemplo.com',
            ],
            'login:senha:url (endereço no fim)' => [
                'fulano8600000000@gmail.com:Fulano123:https://app.exemplo.com/',
                'fulano8600000000@gmail.com', 'Fulano123', 'app.exemplo.com',
            ],
            'senha:login:url (endereço no fim, invertido)' => [
                'Amostra2026:beltrano21@gmail.com:https://streaming.exemplo.com/fi-en/login',
                'beltrano21@gmail.com', 'Amostra2026', 'streaming.exemplo.com',
            ],
            'login:url:senha (endereço no meio)' => [
                'ana@corp.com.br:https://corp.com.br/entrar:Senha!123',
                'ana@corp.com.br', 'Senha!123', 'corp.com.br',
            ],
            'android:// com base64 que tem @ e ==' => [
                'ID android://Qjt6qgMs9O3Y3i4wvpb5wPj1l5F7SlK7pJIUmDdO-CF2Dw7EULsNHQy-hi7D5OwDtpWtCnCDkO4OoqY9dZghDw==@tr.gov.saglik.MHRSMOBIL/:10000000146:Senha2007',
                '10000000146', 'Senha2007', 'tr.gov.saglik.mhrsmobil',
            ],
            'smtp:// com porta' => [
                'smtp://smtp.corp.com.br:465:ana@corp.com.br:Senha1',
                'ana@corp.com.br', 'Senha1', 'smtp.corp.com.br',
            ],
            'senha de seis dígitos não é porta' => [
                'TW https://www.ris.gov.tw/apply/main:A100000001:123456',
                'A100000001', '123456', 'www.ris.gov.tw',
            ],
            'senha com dois-pontos dentro' => [
                'https://corp.com.br/x:ana@corp.com.br:se:nha:123',
                'ana@corp.com.br', 'se:nha:123', 'corp.com.br',
            ],
        ];
    }

    #[DataProvider('ordens')]
    public function test_it_finds_the_login_and_the_password_whatever_the_order(string $line, string $login, string $password, string $host): void
    {
        $r = $this->parse($line);

        $this->assertTrue($r['ok'], "a linha devia ser lida: {$line}");
        $this->assertSame($login, $r['username']);
        $this->assertSame($password, $r['password']);
        $this->assertSame($host, $r['site_host']);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function recusas(): array
    {
        return [
            'endereço no fim e nenhum campo é e-mail' => ['Usuaria999:CIDADE2026:https://www.exemplo88.net/', 'ordem_ambigua'],
            'nenhum campo é endereço' => ['usuario01:Senha-Teste-0001:x', 'sem_endereco'],
            'dois campos são endereço' => ['https://a.com/x:https://b.com/y:z', 'dois_enderecos'],
            'menos de três campos' => ['https://a.com/login:soisso', 'campos_de_menos'],
            'linha vazia' => ['   ', 'vazia'],
        ];
    }

    #[DataProvider('recusas')]
    public function test_it_refuses_instead_of_guessing(string $line, string $reason): void
    {
        $r = $this->parse($line);

        $this->assertFalse($r['ok'], "a linha NÃO devia ser adivinhada: {$line}");
        $this->assertSame($reason, $r['reason']);
    }

    public function test_a_login_that_looks_like_a_host_is_not_taken_for_the_address(): void
    {
        $r = $this->parse('https://corp.com.br/x:john.doe:senha1');

        $this->assertTrue($r['ok']);
        $this->assertSame('john.doe', $r['username']);
        $this->assertSame('corp.com.br', $r['site_host']);
    }

    public function test_only_a_company_email_is_worth_keeping(): void
    {
        $company = $this->parse('https://corp.com.br/x:ana@exemplocorp.com:Senha1');
        $this->assertTrue($company['corporate']);
        $this->assertFalse($company['free_mail']);
        $this->assertSame('exemplocorp.com', $company['domain']);

        $free = $this->parse('https://corp.com.br/x:ana@gmail.com:Senha1');
        $this->assertFalse($free['corporate'], 'gmail não deve ser guardado');
        $this->assertTrue($free['free_mail']);

        $noEmail = $this->parse('https://corp.com.br/x:usuario01:Senha1');
        $this->assertTrue($noEmail['ok'], 'a linha é legível');
        $this->assertFalse($noEmail['corporate'], 'sem e-mail não há domínio de empresa');
        $this->assertNull($noEmail['domain']);
    }

    /** A regra da marca é o que pega os 127 domínios da Microsoft sem cadastrar um por um. */
    public function test_the_brand_rule_catches_the_country_domains_without_catching_companies(): void
    {
        $list = app(FreeMailList::class);

        foreach (['hotmail.com', 'hotmail.co.uk', 'live.com.br', 'outlook.fr', 'yahoo.com.ar', 'msn.com'] as $domain) {
            $this->assertTrue($list->has($domain), "{$domain} é webmail genérico");
        }

        foreach (['live.minhaempresa.com', 'mail.minhaempresa.com', 'petrobras.com.br', 'viit.ac.in'] as $domain) {
            $this->assertFalse($list->has($domain), "{$domain} é de empresa");
        }
    }
}
