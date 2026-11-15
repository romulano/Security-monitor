<?php

namespace Tests\Feature\Security;

use App\Models\SecuritySite;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Lookalike\RdapClient;
use App\Services\Security\TlsProbe;
use App\Services\Security\TlsReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Alerts\FakeDnsResolver;

/** Site do admin, DNS e HTTP combinados de antemão: nenhum teste da monitoria sai para a rede. */
trait SecurityTestHelpers
{
    protected FakeDnsResolver $dns;

    protected function setUpSecurity(): void
    {
        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
        config(['secmon.security.own_ips' => []]);
        $this->fakeRdap(now()->addYear()->toIso8601String());
    }

    /** O registro do domínio (RDAP) de mentira: só a data de vencimento, ou nada. */
    protected function fakeRdap(?string $expiresAt): void
    {
        $this->app->instance(RdapClient::class, new class($expiresAt) extends RdapClient
        {
            public function __construct(private ?string $expiresAt) {}

            public function domain(string $host): array
            {
                return $this->expiresAt ? ['expires_at' => $this->expiresAt] : [];
            }
        });
    }

    protected function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    protected function site(array $attributes = []): SecuritySite
    {
        return SecuritySite::query()->create($attributes + [
            'user_id' => $this->admin()->id,
            'host' => 'acme.com.br',
            'enabled' => true,
            'interval_minutes' => 360,
            'consent_at' => now(),
            'consent_ip' => '203.0.113.9',
            'verify_token' => 'tokentokentokentokentoken',
            'ownership' => 'txt',
            'next_check_at' => now(),
        ]);
    }

    /** Certificado confiável que vence em `$days` dias, TLS 1.3. */
    protected function tls(int $days = 60, array $overrides = []): TlsReport
    {
        return new TlsReport(...$overrides + [
            'reachable' => true,
            'trusted' => true,
            'notAfter' => CarbonImmutable::now()->addDays($days)->addHour(),
            'subject' => 'acme.com.br',
            'issuer' => 'Let\'s Encrypt',
            'protocol' => 'TLSv1.3',
            'fingerprint' => str_repeat('ab', 32),
        ]);
    }

    protected function fakeTls(?TlsReport $report = null): FakeTlsProbe
    {
        $probe = new FakeTlsProbe($report ?? $this->tls());
        $this->app->instance(TlsProbe::class, $probe);

        return $probe;
    }

    /** O DNS de um site em ordem: resolve para um IP público, com SPF, DMARC e a prova de posse. */
    protected function goodDns(string $host = 'acme.com.br'): void
    {
        $this->dns->set($host, 'ips', ['104.21.1.1'])
            ->set($host, 'txt', ['v=spf1 include:_spf.google.com -all', 'secmon-verify=tokentokentokentokentoken'])
            ->set('_dmarc.'.$host, 'txt', ['v=DMARC1; p=reject; rua=mailto:dmarc@'.$host]);
    }

    /** Cabeçalhos de um site bem configurado. @return array<string, string|list<string>> */
    protected function goodHeaders(array $overrides = []): array
    {
        return $overrides + [
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' https://challenges.cloudflare.com",
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Server' => 'cloudflare',
        ];
    }

    /**
     * O site na rede: página inicial em HTTPS, a porta 80 redirecionando e os caminhos sensíveis em 404.
     * `$paths` troca a resposta de um caminho (ex.: '/.env' => Http::response('APP_KEY=base64:x')).
     *
     * @param  array<string, mixed>  $paths
     */
    protected function fakeWeb(string $host = 'acme.com.br', ?array $headers = null, string $body = '<html><body>ok</body></html>', array $paths = [], int $status = 200): void
    {
        // Os caminhos do teste vêm primeiro (o Http::fake usa o primeiro que casa) e o curinga fica por último.
        $stubs = [];

        foreach ($paths as $path => $response) {
            $stubs["https://{$host}{$path}"] = $response;
        }

        $stubs += [
            "http://{$host}/" => Http::response('', 301, ['Location' => "https://{$host}/"]),
            "https://{$host}/.well-known/security.txt" => Http::response("Contact: mailto:seguranca@{$host}\nExpires: ".now()->addYear()->toIso8601String()."\n"),
            "https://{$host}/" => Http::response($body, $status, $headers ?? $this->goodHeaders()),
            "https://{$host}/*" => Http::response('não existe', 404),
        ];

        $this->fakeHttp($stubs);
    }

    /**
     * Respostas HTTP combinadas, começando do zero: o Http::fake acumula (vale o primeiro que casa), e os
     * cenários que trocam a resposta no meio do teste precisam de um cliente limpo.
     *
     * @param  array<string, mixed>  $stubs
     */
    protected function fakeHttp(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }
}
