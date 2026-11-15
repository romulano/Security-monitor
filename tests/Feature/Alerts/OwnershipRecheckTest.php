<?php

namespace Tests\Feature\Alerts;

use App\Exceptions\ActionRefused;
use App\Models\MonitoredDomain;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\Discovery\NativeDnsResolver;
use App\Services\Domains\DomainOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reconferência da posse (a regra da stack de testes, DomainOwnership::*Copy): o TXT
 * continua no ar? Separa "o TXT sumiu" (para tudo) de "o DNS não respondeu" (pausa e
 * tenta de novo), e não grava nada no domínio. Só vale o TXT do próprio nome, nunca o
 * que chega por CNAME.
 */
class OwnershipRecheckTest extends TestCase
{
    use RefreshDatabase;

    private function domain(bool $verified = true): MonitoredDomain
    {
        return MonitoredDomain::query()->create([
            'user_id' => User::factory()->create()->id,
            'domain' => 'empresa.com.br',
            'verify_token' => '9f2c41d07b8e3a65',
            'verified_at' => $verified ? now()->subDays(3) : null,
            'verify_checked_at' => now()->subDays(3),
        ]);
    }

    private function dns(): FakeDnsResolver
    {
        $this->app->instance(DnsResolver::class, $dns = new FakeDnsResolver);

        return $dns;
    }

    private function recheck(MonitoredDomain $domain): ?bool
    {
        return app(DomainOwnership::class)->recheckCopy($domain);
    }

    public function test_the_txt_still_there_is_present(): void
    {
        $this->dns()->set('empresa.com.br', 'txt', ['v=spf1 -all', ' secmon-verify=9f2c41d07b8e3a65 ']);

        $this->assertTrue($this->recheck($this->domain()));
    }

    public function test_a_txt_that_left_is_missing(): void
    {
        $dns = $this->dns()->set('empresa.com.br', 'txt', ['secmon-verify=outro-token']);
        $domain = $this->domain();

        $this->assertFalse($this->recheck($domain));

        $dns->set('empresa.com.br', 'txt', []);
        $this->assertFalse($this->recheck($domain));
    }

    public function test_a_dns_that_did_not_answer_is_unknown_not_missing(): void
    {
        $dns = $this->dns()->set('empresa.com.br', 'txt', ['secmon-verify=9f2c41d07b8e3a65']);
        $dns->failing['empresa.com.br'] = 1;

        $this->assertNull($this->recheck($this->domain()));
        $this->assertContains('falhou empresa.com.br', $dns->asked);
    }

    public function test_a_txt_that_arrives_through_a_cname_belongs_to_the_target_and_proves_nothing(): void
    {
        // Achado do Red Team (P9): loja.empresa.com.br é um apelido para a zona de outro (uma agência,
        // um domínio vencido registrado de novo). Quem controla o destino publica lá o token dele.
        $dns = $this->dns()
            ->set('loja.empresa.com.br', 'cname', ['x.plataforma.com'])
            ->set('x.plataforma.com', 'txt', ['secmon-verify=9f2c41d07b8e3a65']);
        $domain = $this->domain(verified: false);
        $domain->update(['domain' => 'loja.empresa.com.br']);

        $this->assertSame(['secmon-verify=9f2c41d07b8e3a65'], $dns->txtOrNull('loja.empresa.com.br'), 'o DNS segue o apelido, como o de verdade');
        $this->assertSame([], $dns->txtOwnedOrNull('loja.empresa.com.br'));
        $this->assertFalse($this->recheck($domain));
        $this->assertFalse(app(DomainOwnership::class)->verifyCopy($domain));
        $this->assertNull($domain->refresh()->verified_at);

        try {
            app(DomainOwnership::class)->confirmCopy($domain);
            $this->fail('O apelido não devia provar a posse.');
        } catch (ActionRefused $e) {
            $this->assertSame('loja.empresa.com.br é um apelido (CNAME) de x.plataforma.com. Um apelido não tem TXT próprio: o TXT que aparece nele é o do destino, e não prova que o nome é seu. Cadastre o domínio principal, onde o TXT pode ficar.', $e->getMessage());
        }
    }

    public function test_the_native_resolver_keeps_only_the_records_owned_by_the_name_asked(): void
    {
        // A forma do dns_get_record: na resposta que veio por CNAME, o `host` é o do destino.
        $dns = new class extends NativeDnsResolver
        {
            protected function answer(string $host, int $type): ?array
            {
                return match ("{$type} {$host}") {
                    DNS_TXT.' empresa.test' => [
                        ['host' => 'Empresa.TEST.', 'type' => 'TXT', 'txt' => 'secmon-verify=aa', 'entries' => ['secmon-verify=', 'aa']],
                        ['host' => 'empresa.test', 'type' => 'TXT', 'txt' => 'v=spf1 -all'],
                        ['host' => 'x.plataforma.test', 'type' => 'TXT', 'txt' => 'secmon-verify=bb'],
                    ],
                    DNS_CNAME.' empresa.test', DNS_CNAME.' sem-cname.test' => [],
                    DNS_CNAME.' loja.empresa.test' => [['host' => 'loja.empresa.test', 'type' => 'CNAME', 'target' => 'x.plataforma.test']],
                    DNS_TXT.' loja.empresa.test' => [['host' => 'x.plataforma.test', 'type' => 'TXT', 'txt' => 'secmon-verify=bb', 'entries' => ['secmon-verify=bb']]],
                    // O musl corta o dono no NUL: "loja2.empresa.test\0.plataforma.test" chega como o nome perguntado.
                    DNS_CNAME.' loja2.empresa.test' => [['host' => 'loja2.empresa.test', 'type' => 'CNAME', 'target' => 'y.plataforma.test']],
                    DNS_TXT.' loja2.empresa.test' => [['host' => 'loja2.empresa.test', 'type' => 'TXT', 'txt' => 'secmon-verify=b2', 'entries' => ['secmon-verify=b2']]],
                    DNS_TXT.' sem-cname.test' => null,
                    default => null,
                };
            }
        };

        $this->assertSame(['secmon-verify=aa', 'v=spf1 -all'], $dns->txtOwnedOrNull('empresa.test'), 'sem diferença de maiúsculas nem do ponto final, e sem o TXT de outro dono');
        $this->assertSame(['secmon-verify=bb'], $dns->txtOrNull('loja.empresa.test'));
        $this->assertSame([], $dns->txtOwnedOrNull('loja.empresa.test'));
        $this->assertSame([], $dns->txtOwnedOrNull('loja2.empresa.test'), 'um apelido nunca prova a posse, diga o host o que disser');
        $this->assertNull($dns->txtOwnedOrNull('mudo.test'), 'o DNS que não respondeu (aqui, nem o CNAME) continua "não sei"');
        $this->assertNull($dns->txtOwnedOrNull('sem-cname.test'), 'o TXT que não respondeu também');
    }

    public function test_recheck_also_covers_a_proven_domain_and_writes_nothing(): void
    {
        $this->dns()->set('empresa.com.br', 'txt', []);
        $domain = $this->domain(verified: true)->refresh();
        $before = $domain->only(['verified_at', 'verify_checked_at', 'updated_at']);

        // O verify() pararia no "já comprovado"; o recheck pergunta de novo, e só pergunta.
        $this->assertFalse($this->recheck($domain));
        $this->assertEquals($before, $domain->refresh()->only(['verified_at', 'verify_checked_at', 'updated_at']));
    }

    public function test_without_a_fake_the_dns_of_the_tests_does_not_answer(): void
    {
        // O TestCase liga um DNS que não responde: a reconferência dá "não sei", nunca "sumiu".
        $this->assertNull($this->recheck($this->domain()));
        $this->assertNull(app(DnsResolver::class)->ptrOrNull('203.0.113.10'));
    }

    public function test_the_fake_dns_answers_ptr_by_ip(): void
    {
        $dns = $this->dns()->set('203.0.113.10', 'ptr', ['www.empresa.com.br']);

        $this->assertSame(['www.empresa.com.br'], $dns->ptrOrNull('203.0.113.10'));
        $this->assertSame([], $dns->ptrOrNull('203.0.113.11'));

        $dns->failing['203.0.113.10'] = 1;
        $this->assertNull($dns->ptrOrNull('203.0.113.10'));
    }

    public function test_the_reverse_name_of_an_ip(): void
    {
        $this->assertSame('10.113.0.203.in-addr.arpa', NativeDnsResolver::reverseName('203.0.113.10'));
        $this->assertSame(
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
            NativeDnsResolver::reverseName('2001:db8::1'),
        );
        $this->assertNull(NativeDnsResolver::reverseName('empresa.com.br'));
        $this->assertSame([], (new NativeDnsResolver)->ptrOrNull('não é ip'));
    }
}
