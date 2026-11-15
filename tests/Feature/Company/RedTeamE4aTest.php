<?php

namespace Tests\Feature\Company;

use App\Models\Company;
use App\Models\LookalikeWatch;
use App\Models\NotificationChannel;
use App\Models\OwnedDomain;
use App\Models\OwnedSubdomain;
use App\Models\SubdomainAlert;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Domains\SubdomainCheck;
use App\Services\Lookalike\RadarSubdomains;
use App\Services\Lookalike\SubdomainAlertMessages;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\AlertsTestHelpers;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * Red Team da E4a. `rt_*`: passava no 886cd62 e o patch fecha; `evidencia_*`: o que resistiu.
 *
 * - M1: o DNS mudo numa consulta de NS (a zona de um nível acima do nome, e com o Y3 da E3b1 também a de cada salto do
 *   CNAME) vira `zona_desconhecida`, que é `outside`: o Radar avisa "passou a apontar para fora" (Alto) por um timeout, e
 *   grava o estado novo. No dia seguinte o DNS responde, volta "dentro", e o próximo timeout avisa de novo.
 * - M2: o aviso de tomada diz o 1º salto do CNAME (o da empresa, que resolve) como "o destino que não resolve"; e o
 *   nome do salto vem do DNS de terceiro sem filtro, direto no Markdown do Discord.
 */
class RedTeamE4aTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase, VisibilityFixtures;

    private FakeDnsResolver $dns;

    private Company $acme;

    private User $ana;

    private LookalikeWatch $watch;

    private OwnedDomain $owned;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->app->instance(DnsResolver::class, $this->dns = new FakeDnsResolver);
        config(['secmon.lookalikes.subdomains_enabled' => true]);
        Settings::set('alerts.realtime_enabled', true);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com.br');
        $this->owned = OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'domain' => 'acme.com.br', 'source' => 'company',
            'proof_token' => $this->acme->fresh()->domain_token, 'verified_at' => now(), 'checked_at' => now(), 'check_status' => 'present']);
        $this->watch = $this->watchOf($this->ana, $this->acme);
        $this->watch->forceFill(['status' => 'active', 'completed_at' => now(), 'step_reached' => 3])->save();
        $this->watch->domain->forceFill(['domain' => 'acme.com.br', 'verified_at' => now()])->save();
        $this->slackChannel($this->ana);
    }

    private function sub(string $name): OwnedSubdomain
    {
        $check = app(SubdomainCheck::class)->check('acme.com.br', $name);

        return OwnedSubdomain::query()->create(['owned_domain_id' => $this->owned->id, 'name' => $name, 'origin' => 'manual', 'status' => 'active',
            'reason' => $check['reason'], 'facts' => $check['facts'], 'first_seen_at' => now(), 'activated_at' => now()]);
    }

    public function test_rt_m1_a_mute_ns_answer_is_not_a_change_to_outside(): void
    {
        $this->dns->set('app.loja.acme.com.br', 'ips', ['198.51.100.7']);
        $sub = $this->sub('app.loja.acme.com.br');
        $this->assertFalse($sub->isOutside());
        $facts = $sub->refresh()->facts;

        // O NS de loja.acme.com.br não responde nesta varredura (o resto responde).
        $this->dns->failing['loja.acme.com.br'] = 1;
        app(RadarSubdomains::class)->scan($this->watch->refresh());

        // No 886cd62: aviso "passou a apontar para fora" e o estado gravado como fora da zona.
        $this->assertSame(0, SubdomainAlert::query()->count());
        $this->assertEquals(collect($facts)->sortKeys()->all(), collect($sub->refresh()->facts)->sortKeys()->all());
    }

    public function test_rt_m1_also_covers_a_cname_hop_whose_zone_does_not_answer(): void
    {
        // O hopZone da E3b1 (Y3): o salto do CNAME dentro do domínio cujo NS não responde dá a zona `desconhecido`.
        $dns = new class extends FakeDnsResolver
        {
            public array $nsFail = [];

            public function nsOrNull(string $host): ?array
            {
                return isset($this->nsFail[$host]) ? null : parent::nsOrNull($host);
            }
        };
        $this->app->instance(DnsResolver::class, $dns);
        $dns->set('www.acme.com.br', 'cname', ['web.acme.com.br'])->set('web.acme.com.br', 'ips', ['198.51.100.7']);
        $sub = $this->sub('www.acme.com.br');
        $this->assertFalse($sub->isOutside());
        $facts = $sub->refresh()->facts;

        $dns->nsFail['web.acme.com.br'] = true;
        $this->assertSame('desconhecido', app(SubdomainCheck::class)->check('acme.com.br', 'www.acme.com.br')['facts']['zone']);
        $lines = app(RadarSubdomains::class)->scan($this->watch->refresh());

        $this->assertSame(0, SubdomainAlert::query()->count());
        $this->assertEquals(collect($facts)->sortKeys()->all(), collect($sub->refresh()->facts)->sortKeys()->all());
        $this->assertContains('1 sem resposta do DNS agora: ficam como estavam até a próxima varredura.', $lines);
    }

    public function test_rt_m2_the_takeover_names_the_dangling_end_and_never_raw_dns_text(): void
    {
        $this->dns->set('loja.acme.com.br', 'cname', ['shop.acme.com.br'])->set('shop.acme.com.br', 'cname', ['acme.myshopify.com'])
            ->set('acme.myshopify.com', 'ips', ['23.227.38.65']);
        $this->sub('loja.acme.com.br');
        $this->dns->set('acme.myshopify.com', 'ips', []);
        app(RadarSubdomains::class)->scan($this->watch->refresh());

        $alert = SubdomainAlert::query()->sole()->load('subdomain.ownedDomain', 'watch');
        $parts = app(SubdomainAlertMessages::class)->parts($alert);
        $this->assertStringContainsString('aponta para acme.myshopify.com, que não resolve', $parts['summary']);

        // Um salto com texto de Markdown (o DNS de terceiro escolhe o nome): nunca vira link no Discord.
        $alert->forceFill(['facts' => ['cname' => ['shop.acme.com.br', '[Ver](https://phish.example)'], 'cname_out' => true, 'ips' => []]])->save();
        $discord = app(SubdomainAlertMessages::class)->alert($alert->refresh()->load('subdomain.ownedDomain', 'watch'), new NotificationChannel(['type' => 'discord']));
        $this->assertStringNotContainsString('](https://phish.example)', json_encode($discord, JSON_UNESCAPED_SLASHES));
    }
}
