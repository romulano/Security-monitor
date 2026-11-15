<?php

namespace Tests\Feature\Security;

use App\Services\Security\SiteOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A prova de que o site é de quem o cadastrou: o servidor do Security Monitor ou um TXT. Declarar não basta. */
class SiteOwnershipTest extends TestCase
{
    use RefreshDatabase, SecurityTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSecurity();
    }

    public function test_a_site_that_resolves_only_to_the_monitor_server_is_owned_automatically(): void
    {
        config(['secmon.security.own_ips' => ['203.0.113.10']]);
        $this->dns->set('acme.com.br', 'ips', ['203.0.113.10']);

        $this->assertSame('server', app(SiteOwnership::class)->check($this->site(['ownership' => null])));
    }

    public function test_one_address_outside_the_server_is_not_enough(): void
    {
        config(['secmon.security.own_ips' => ['203.0.113.10']]);
        $this->dns->set('acme.com.br', 'ips', ['203.0.113.10', '104.21.1.1']);

        $this->assertNull(app(SiteOwnership::class)->check($this->site(['ownership' => null])));
    }

    public function test_without_configured_server_addresses_the_automatic_proof_never_applies(): void
    {
        config(['secmon.security.own_ips' => []]);
        $this->dns->set('acme.com.br', 'ips', ['104.21.1.1']);

        $this->assertNull(app(SiteOwnership::class)->check($this->site(['ownership' => null])));
    }

    public function test_the_txt_record_with_the_token_proves_ownership_at_the_host_or_the_main_domain(): void
    {
        $site = $this->site(['host' => 'app.acme.com.br', 'ownership' => null]);

        $this->dns->set('app.acme.com.br', 'txt', ['secmon-verify=tokentokentokentokentoken']);
        $this->assertSame('txt', app(SiteOwnership::class)->check($site));

        $this->dns->set('app.acme.com.br', 'txt', []);
        $this->dns->set('acme.com.br', 'txt', ['v=spf1 -all', ' secmon-verify=tokentokentokentokentoken ']);
        $this->assertSame('txt', app(SiteOwnership::class)->check($site), 'o TXT do domínio principal vale para os subdomínios');
    }

    public function test_a_txt_with_another_token_or_another_prefix_proves_nothing(): void
    {
        $site = $this->site(['ownership' => null]);

        foreach (['secmon-verify=outro-token', 'secmon-verify=', 'tokentokentokentokentoken', 'secmon-verify=tokentokentokentokentoken-extra', 'SECMON-VERIFY=tokentokentokentokentoken'] as $txt) {
            $this->dns->set('acme.com.br', 'txt', [$txt]);

            $this->assertNull(app(SiteOwnership::class)->check($site), $txt);
        }
    }

    public function test_a_txt_that_arrives_through_a_cname_is_the_target_s_and_proves_nothing(): void
    {
        $site = $this->site(['host' => 'app.acme.com.br', 'ownership' => null]);

        $this->dns->set('app.acme.com.br', 'cname', ['app.agencia.com'])
            ->set('app.agencia.com', 'txt', ['secmon-verify=tokentokentokentokentoken']);
        $this->assertNull(app(SiteOwnership::class)->check($site), 'quem controla o destino do apelido não prova o site');

        $this->dns->set('acme.com.br', 'txt', ['secmon-verify=tokentokentokentokentoken']);
        $this->assertSame('txt', app(SiteOwnership::class)->check($site), 'o TXT do domínio principal continua valendo');
    }

    public function test_the_consent_checkbox_alone_proves_nothing(): void
    {
        $site = $this->site(['ownership' => null, 'consent_at' => now()]);

        $this->assertNull(app(SiteOwnership::class)->check($site));
    }

    public function test_refresh_stores_the_proof_and_the_time_and_takes_it_back_when_the_proof_disappears(): void
    {
        $site = $this->site(['ownership' => null]);
        $this->dns->set('acme.com.br', 'txt', ['secmon-verify=tokentokentokentokentoken']);

        $this->assertSame('txt', app(SiteOwnership::class)->refresh($site));
        $this->assertSame('txt', $site->fresh()->ownership);
        $this->assertNotNull($site->fresh()->ownership_checked_at);

        $this->dns->set('acme.com.br', 'txt', []);
        $this->assertNull(app(SiteOwnership::class)->refresh($site));
        $this->assertNull($site->fresh()->ownership);
    }
}
