<?php

namespace Tests\Feature\Security;

use App\Services\Lookalike\RdapClient;
use App\Services\Security\CheckResult;
use App\Services\Security\SiteScanner;
use App\Services\Security\TlsReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Certificado, security.txt, SPF, DMARC e vencimento do domínio. */
class OtherChecksTest extends TestCase
{
    use RefreshDatabase, SecurityTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSecurity();
        $this->goodDns();
        $this->fakeTls();
        $this->fakeWeb();
    }

    /** @return array<string, CheckResult> */
    private function scan(): array
    {
        return collect(app(SiteScanner::class)->scan($this->site())->results)->keyBy(fn (CheckResult $r) => $r->key())->all();
    }

    // ---- TLS ---------------------------------------------------------------

    public function test_a_trusted_certificate_far_from_expiring_on_tls_13_is_ok(): void
    {
        $r = $this->scan();

        $this->assertSame('ok', $r['tls.valid|']->status);
        $this->assertSame('ok', $r['tls.expiry|']->status);
        $this->assertStringStartsWith('vence em 60 dias', $r['tls.expiry|']->value);
        $this->assertSame(['ok', 'TLSv1.3'], [$r['tls.protocol|']->status, $r['tls.protocol|']->value]);
    }

    public function test_expiry_is_a_warning_inside_14_days_and_critical_inside_7_or_after(): void
    {
        foreach ([[20, 'ok'], [14, 'aviso'], [10, 'aviso'], [7, 'critico'], [2, 'critico'], [-3, 'critico']] as [$days, $status]) {
            $this->fakeTls($this->tls($days));

            $this->assertSame($status, $this->scan()['tls.expiry|']->status, "{$days} dias");
        }

        $this->fakeTls($this->tls(-3));
        $this->assertStringContainsString('venceu há 3 dia(s)', $this->scan()['tls.expiry|']->value);
    }

    public function test_an_untrusted_chain_and_old_tls_are_critical(): void
    {
        $this->fakeTls($this->tls(60, ['trusted' => false, 'error' => 'certificado autoassinado', 'protocol' => 'TLSv1.1']));

        $r = $this->scan();

        $this->assertSame('critico', $r['tls.valid|']->status);
        $this->assertSame('certificado autoassinado', $r['tls.valid|']->value);
        $this->assertSame('critico', $r['tls.protocol|']->status);
    }

    public function test_a_host_without_tls_on_443_is_critical_and_a_blocked_one_is_untestable(): void
    {
        $this->fakeTls(TlsReport::unreachable('sem resposta TLS na porta 443'));
        $this->assertSame('critico', $this->scan()['tls.valid|']->status);

        $this->fakeTls(TlsReport::blocked('acme.com.br aponta para 10.0.0.9 (rede privada)'));
        $this->assertSame('nao_testavel', $this->scan()['tls.valid|']->status);
    }

    public function test_the_certificate_is_never_stored_only_names_dates_and_the_protocol(): void
    {
        $r = $this->scan();

        $this->assertStringNotContainsString('BEGIN CERTIFICATE', json_encode(array_map(fn ($x) => $x->value, $r)));
    }

    // ---- security.txt ----------------------------------------------------------

    public function test_a_valid_security_txt_is_ok_and_a_missing_or_expired_one_is_a_warning(): void
    {
        $this->assertSame('ok', $this->scan()['wk.security_txt|']->status);

        $this->fakeWeb(paths: ['/.well-known/security.txt' => Http::response('não existe', 404)]);
        $this->assertSame('aviso', $this->scan()['wk.security_txt|']->status);

        $this->fakeWeb(paths: ['/.well-known/security.txt' => Http::response("Contact: mailto:a@acme.com.br\nExpires: 2020-01-01T00:00:00Z\n")]);
        $this->assertStringContainsString('Expires venceu', $this->scan()['wk.security_txt|']->value);

        $this->fakeWeb(paths: ['/.well-known/security.txt' => Http::response("Contact: mailto:a@acme.com.br\n")]);
        $this->assertSame('sem o campo Expires', $this->scan()['wk.security_txt|']->value);

        $this->fakeWeb(paths: ['/.well-known/security.txt' => Http::response("Expires: 2099-01-01T00:00:00Z\n")]);
        $this->assertSame('aviso', $this->scan()['wk.security_txt|']->status, 'sem Contact não vale');
    }

    public function test_security_txt_is_a_passive_check_that_runs_without_ownership(): void
    {
        $this->dns->set('acme.com.br', 'txt', ['v=spf1 -all']);

        $this->assertSame('ok', $this->scan()['wk.security_txt|']->status);
    }

    // ---- SPF e DMARC ---------------------------------------------------------

    public function test_spf_is_judged_by_its_ending_and_by_how_many_records_there_are(): void
    {
        $cases = [
            [['v=spf1 include:_spf.google.com -all'], 'ok'],
            [['v=spf1 include:_spf.google.com ~all'], 'ok'],
            [['v=spf1 include:_spf.google.com ?all'], 'aviso'],
            [['v=spf1 include:_spf.google.com'], 'aviso'],
            [['v=spf1 +all'], 'critico'],
            [['v=spf1 all'], 'critico'],
            [['v=spf1 -all', 'v=spf1 include:outro.com -all'], 'critico'],
            [['secmon-verify=tokentokentokentokentoken'], 'aviso'],
        ];

        foreach ($cases as [$txt, $status]) {
            $this->dns->set('acme.com.br', 'txt', [...$txt, 'secmon-verify=tokentokentokentokentoken']);

            $this->assertSame($status, $this->scan()['dns.spf|']->status, implode(' | ', $txt));
        }
    }

    public function test_dmarc_must_exist_and_must_not_be_p_none(): void
    {
        foreach ([['v=DMARC1; p=reject', 'ok'], ['v=DMARC1; p=quarantine; pct=50', 'ok'], ['v=DMARC1; p=none; rua=mailto:x@acme.com.br', 'aviso']] as [$txt, $status]) {
            $this->dns->set('_dmarc.acme.com.br', 'txt', [$txt]);
            $this->assertSame($status, $this->scan()['dns.dmarc|']->status, $txt);
        }

        $this->dns->set('_dmarc.acme.com.br', 'txt', []);
        $this->assertStringContainsString('sem DMARC', $this->scan()['dns.dmarc|']->value);
    }

    public function test_e_mail_records_are_read_at_the_registrable_domain_not_at_the_subdomain(): void
    {
        $this->goodDns('acme.com.br');
        $this->dns->set('app.acme.com.br', 'ips', ['104.21.1.1'])->set('app.acme.com.br', 'txt', ['secmon-verify=tokentokentokentokentoken']);
        $this->fakeWeb('app.acme.com.br');

        $report = app(SiteScanner::class)->scan($this->site(['host' => 'app.acme.com.br']));

        $by = collect($report->results)->keyBy(fn ($r) => $r->key());
        $this->assertSame('ok', $by['dns.spf|']->status);
        $this->assertSame('ok', $by['dns.dmarc|']->status);
    }

    // ---- vencimento do domínio ---------------------------------------------

    public function test_the_domain_expiry_follows_the_registry_date(): void
    {
        foreach ([[400, 'ok'], [25, 'aviso'], [5, 'critico'], [-2, 'critico']] as [$days, $status]) {
            $this->fakeRdap(CarbonImmutable::now()->addDays($days)->toIso8601String());
            Cache::flush();

            $this->assertSame($status, $this->scan()['dom.expiry|']->status, "{$days} dias");
        }
    }

    public function test_a_registry_without_the_date_is_untestable(): void
    {
        $this->fakeRdap(null);

        $this->assertSame('nao_testavel', $this->scan()['dom.expiry|']->status);
    }

    public function test_the_registry_is_asked_once_a_day_not_on_every_scan(): void
    {
        $calls = 0;
        $this->app->instance(RdapClient::class, new class(function () use (&$calls) {
            $calls++;
        }) extends RdapClient
        {

            public function __construct(private \Closure $onCall) {}

            public function domain(string $host): array
            {
                ($this->onCall)();

                return ['expires_at' => now()->addYear()->toIso8601String()];
            }
        });

        $this->scan();
        $this->scan();
        $this->scan();

        $this->assertSame(1, $calls);
    }

    public function test_one_check_that_blows_up_becomes_untestable_without_stopping_the_others(): void
    {
        $this->app->instance(RdapClient::class, new class extends RdapClient
        {
            public function __construct() {}

            public function domain(string $host): array
            {
                throw new \RuntimeException('RDAP caiu');
            }
        });

        $r = $this->scan();

        $this->assertSame('nao_testavel', $r['dom.expiry|']->status);
        $this->assertStringContainsString('RDAP caiu', $r['dom.expiry|']->value);
        $this->assertSame('ok', $r['tls.valid|']->status);
    }
}
