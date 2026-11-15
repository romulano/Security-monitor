<?php

namespace Tests\Feature\Alerts;

use App\Models\AlertProfile;
use App\Models\MonitoredDomain;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\InterviewService;
use App\Services\Alerts\ProfileIndex;
use App\Services\Alerts\ProfilePreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Passo 5: o DNS público do domínio vira "achamos Zoho, SendGrid e Zendesk, quer adicionar?". */
class DnsSuggestionsTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
        $this->mock(ProfilePreview::class)->shouldReceive('run')->andReturn([
            'total' => 0, 'by_level' => [], 'per_week' => 0, 'error' => null, 'examples' => [],
        ]);
        Http::fake();
    }

    private function step(int $n, array $answers, array $extra = [])
    {
        return $this->put(route('alerts.interview.update', $n), ['answers' => $answers] + $extra);
    }

    /** O DNS de um site como o fatalmodel.com: Zoho no MX, quatro serviços no SPF, Cloudflare no NS. */
    private function acmeDns(): void
    {
        $this->dns->set('acmepay.com.br', 'mx', ['mx.zoho.com', 'mx2.zoho.com'])
            ->set('acmepay.com.br', 'txt', ['v=spf1 include:zohomail.com include:amazonses.com include:mail.zendesk.com include:sendgrid.net -all', 'google-site-verification=abc'])
            ->set('acmepay.com.br', 'ns', ['gina.ns.cloudflare.com', 'luke.ns.cloudflare.com']);
    }

    /** Cliente que já marcou Cloudflare e Amazon SES: isso não volta como sugestão. */
    private function clientWithCloudflareAndSes(): AlertProfile
    {
        $user = $this->client();
        $this->actingAs($user);
        $this->step(3, ['web_servers' => ['cloudflare']]);
        $this->step(4, ['tools' => ['amazon-ses']]);

        return AlertProfile::query()->where('user_id', $user->id)->firstOrFail();
    }

    public function test_saving_the_domain_reads_the_public_dns_and_asks(): void
    {
        $this->acmeDns();
        $profile = $this->clientWithCloudflareAndSes();

        // Sem aceite e sem TXT de posse: o DNS é público e o site não é tocado.
        $this->step(5, ['has_domain' => 'sim', 'domain' => 'acmepay.com.br'])
            ->assertRedirect(route('alerts.interview', 5).'#sugestoes-dns')
            ->assertSessionHas('status', 'Achamos 3 tecnologia(s) no DNS público do domínio. Quer adicionar ao perfil?');
        Http::assertNothingSent();
        $this->assertSame(['mx acmepay.com.br', 'txt acmepay.com.br', 'ns acmepay.com.br', 'cname www.acmepay.com.br'], $this->dns->asked);

        $domain = MonitoredDomain::query()->sole();
        $this->assertNull($domain->consent_at);
        $this->assertEqualsCanonicalizing(['zoho-mail', 'zendesk', 'sendgrid'], array_column($domain->dns_suggestions, 'slug'));

        $this->get(route('alerts.interview', 5))->assertOk()
            ->assertSee('Achamos no DNS público de')
            ->assertSeeInOrder(['Zoho Mail', 'MX mx.zoho.com', 'entra em: E-mail e identidade'])
            ->assertSeeInOrder(['SendGrid', 'SPF include:sendgrid.net', 'entra em: Ferramentas da empresa'])
            ->assertSee('SPF include:mail.zendesk.com')
            ->assertDontSee('value="cloudflare"', false)
            ->assertSee('Ao salvar, lemos o DNS público do domínio');

        // Marca Zoho e SendGrid; Zendesk, desmarcado, é dispensado.
        $this->post(route('alerts.domain.suggestions'), ['adicionar' => ['zoho-mail', 'sendgrid'], 'acao' => 'adicionar'])
            ->assertSessionHas('status', 'Zoho Mail e SendGrid entraram no perfil.');

        $values = app(InterviewService::class)->values($profile->refresh());
        $this->assertSame(['zoho-mail'], $values['identity']);
        $this->assertSame(['amazon-ses', 'sendgrid'], $values['tools']);
        $this->assertEqualsCanonicalizing(['cloudflare', 'amazon-ses', 'zoho-mail', 'sendgrid'], $profile->techs()->pluck('slug')->all());
        $this->assertSame(['interview'], $profile->techs()->pluck('alert_profile_techs.source')->unique()->values()->all());
        $this->assertSame(
            ['sendgrid' => 'added', 'zendesk' => 'dismissed', 'zoho-mail' => 'added'],
            collect($domain->refresh()->dns_suggestions)->sortBy('slug')->pluck('status', 'slug')->all(),
        );

        $this->get(route('alerts.interview', 5))->assertDontSee('Achamos no DNS público de');
        $this->get(route('alerts.interview', 4))->assertSee('value="sendgrid" checked', false)->assertSee('value="zoho-mail" checked', false);

        // Sem pendência, "Continuar" segue; o DNS não é relido no mesmo dia.
        $asked = count($this->dns->asked);
        $this->step(5, ['has_domain' => 'sim', 'domain' => 'acmepay.com.br'])->assertRedirect(route('alerts.interview', 6));
        $this->assertCount($asked, $this->dns->asked);

        // No dia seguinte o DNS é relido, e o dispensado não volta.
        $this->travel(25)->hours();
        $this->step(5, ['has_domain' => 'sim', 'domain' => 'acmepay.com.br'])->assertRedirect(route('alerts.interview', 6));
        $this->assertGreaterThan($asked, count($this->dns->asked));
    }

    public function test_not_now_dismisses_everything(): void
    {
        $this->acmeDns();
        $profile = $this->clientWithCloudflareAndSes();
        $this->step(5, ['has_domain' => 'sim', 'domain' => 'acmepay.com.br']);

        $this->post(route('alerts.domain.suggestions'), ['adicionar' => ['zoho-mail'], 'acao' => 'dispensar'])
            ->assertSessionHas('status', 'Sugestões dispensadas. Elas não voltam nas próximas leituras.');

        $this->assertEqualsCanonicalizing(['cloudflare', 'amazon-ses'], $profile->techs()->pluck('slug')->all());
        $this->step(5, ['has_domain' => 'sim', 'domain' => 'acmepay.com.br'])->assertRedirect(route('alerts.interview', 6));
    }

    /** Nenhuma pergunta oferece Registro.br: entra escrito em Ferramentas, onde vira o item do catálogo. */
    public function test_technology_without_a_question_goes_to_tools_as_written(): void
    {
        $this->dns->set('acme.com.br', 'ns', ['a.dns.br', 'b.dns.br']);
        $user = $this->client();
        $this->actingAs($user)->step(5, ['has_domain' => 'sim', 'domain' => 'acme.com.br']);

        $this->get(route('alerts.interview', 5))->assertSeeInOrder(['Registro.br', 'NS a.dns.br', 'entra em: Ferramentas da empresa']);
        $this->post(route('alerts.domain.suggestions'), ['adicionar' => ['registro-br']])->assertSessionHas('status', 'Registro.br entrou no perfil.');

        $profile = AlertProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['Registro.br'], app(InterviewService::class)->values($profile)['tools_outros']);
        $this->assertSame(['registro-br'], $profile->techs()->pluck('slug')->all());
        $this->assertNull($profile->custom_answers);
    }

    public function test_a_new_domain_starts_a_new_reading(): void
    {
        $this->acmeDns();
        $this->dns->set('outra.com.br', 'mx', ['aspmx.l.google.com']);
        $this->clientWithCloudflareAndSes();
        $this->step(5, ['has_domain' => 'sim', 'domain' => 'acmepay.com.br']);

        $this->step(5, ['has_domain' => 'sim', 'domain' => 'outra.com.br'])->assertRedirect(route('alerts.interview', 5).'#sugestoes-dns');

        $this->assertSame(['google-workspace'], array_column(MonitoredDomain::query()->sole()->dns_suggestions, 'slug'));
    }

    public function test_only_the_owner_decides_and_only_with_a_domain(): void
    {
        $this->acmeDns();
        $owner = $this->clientWithCloudflareAndSes();
        $this->step(5, ['has_domain' => 'sim', 'domain' => 'acmepay.com.br']);

        $this->actingAs($this->client())
            ->post(route('alerts.domain.suggestions', ['alerta' => $owner->id]), ['adicionar' => ['zoho-mail']])
            ->assertNotFound();
        $this->post(route('alerts.domain.suggestions'), ['adicionar' => ['zoho-mail']])->assertNotFound();

        // Slug que não foi sugerido não entra.
        $this->actingAs($owner->user)->post(route('alerts.domain.suggestions'), ['adicionar' => ['fortinet']])
            ->assertSessionHas('status', 'Sugestões dispensadas. Elas não voltam nas próximas leituras.');
        $this->assertNotContains('fortinet', $owner->techs()->pluck('slug')->all());
    }
}
