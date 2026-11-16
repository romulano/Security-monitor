<?php

namespace Tests\Feature\Lookalike;

use App\Jobs\ScanLookalikes;
use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\ProfileIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Feature\Alerts\FakeProfileIndex;
use Tests\TestCase;

/** A entrevista do Radar, separada da de notícias: domínio, marca, posse e ativação. */
class RadarInterviewTest extends TestCase
{
    use LookalikeTestHelpers, RefreshDatabase;

    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTempBrowserDir();
        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownBrowserDir();
        parent::tearDown();
    }

    public function test_domain_proven_in_news_is_reused_and_the_radar_starts_right_away(): void
    {
        $profile = $this->profile(null, ['brands' => ['AcmePay', 'Acme Conta']]);
        $user = $profile->user;
        $news = $this->proveRow($profile->domains()->create([
            'domain' => 'acmepay.com.br', 'verify_token' => 'tok123', 'verified_at' => now()->subDay(),
            'consent_at' => now()->subDay(), 'consent_version' => 'v3', 'consent_ip' => '10.0.0.1',
        ]));

        $this->actingAs($user)->get(route('alerts.lookalikes.interview', ['passo' => 1]))
            ->assertOk()
            ->assertSee('Já comprovados no Security Monitor')
            ->assertSee('acmepay.com.br');

        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 1]), ['origem' => (string) $this->provenId($news)])
            ->assertRedirect();

        $watch = LookalikeWatch::query()->sole();
        $domain = $watch->domain;
        $this->assertSame(['draft', 2, $user->id], [$watch->status, $watch->step_reached, $watch->user_id]);
        $this->assertSame([null, []], [$watch->company_name, $watch->brands], 'empresa e marcas não são copiadas da entrevista de notícias: pode ser outra empresa');
        $this->assertNull($domain->profile_id, 'o domínio do Radar não pertence ao alerta');
        $this->assertNotSame($news->id, $domain->id);
        $this->assertTrue($domain->isVerified(), 'a prova de posse vem junto');
        $this->assertSame('v3', $domain->consent_version, 'o aceite v3 já cobre os prints do site');

        $this->actingAs($user)->get(route('alerts.lookalikes.interview', ['passo' => 2, 'radar' => $watch->id]))
            ->assertOk()->assertSee('Exemplos do que vamos conferir')
            ->assertDontSee('value="Acme', false)
            // O JS dos chips só roda em [data-interview]: sem ele o Enter nos nomes da marca envia o passo.
            ->assertSee('id="step-form" method="POST" action="'.route('alerts.lookalikes.interview.update', ['passo' => 2, 'radar' => $watch->id]).'" class="space-y-4" data-interview>', false);
        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 2, 'radar' => $watch->id]), [
            'empresa' => ' Acme Pagamentos S.A. ', 'marcas' => ['AcmePay', 'acmepay', '', 'Acme Pix'],
        ])->assertRedirect(route('alerts.lookalikes.interview', ['passo' => 3, 'radar' => $watch->id]));
        $watch->refresh();
        $this->assertSame(['Acme Pagamentos S.A.', ['AcmePay', 'Acme Pix']], [$watch->company_name, $watch->brands]);

        $this->actingAs($user)->get(route('alerts.lookalikes.interview', ['passo' => 3, 'radar' => $watch->id]))
            ->assertOk()->assertSee('Ativar o Radar');

        $this->actingAs($user)->post(route('alerts.lookalikes.interview.activate', ['radar' => $watch->id]), ['consent' => '1'])
            ->assertRedirect(route('alerts.lookalikes'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Radar ativado'));

        $watch->refresh();
        $this->assertSame('active', $watch->status);
        $this->assertNotNull($watch->completed_at);
        Queue::assertPushed(ScanLookalikes::class, fn (ScanLookalikes $job) => $job->watchId === $watch->id);

        // Excluir o alerta de notícias não leva o Radar junto.
        $this->actingAs($user)->delete(route('alerts.destroy'))->assertRedirect(route('alerts.news'));
        $this->assertNull(MonitoredDomain::query()->find($news->id));
        $this->assertSame('active', $watch->refresh()->status);
    }

    public function test_typed_domain_is_born_verified_and_the_radar_starts_right_away(): void
    {
        $user = $this->client();

        $this->actingAs($user)->get(route('alerts.lookalikes.interview', ['passo' => 1]))
            ->assertOk()->assertDontSee('Já comprovados no Security Monitor');

        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 1]), ['origem' => 'outro', 'outro' => 'https://www.AcmeConta.com.br/entrar']);
        $watch = LookalikeWatch::query()->sole();
        $domain = $watch->domain;
        $this->assertSame('acmeconta.com.br', $domain->domain);
        // Instalação de um dono só: o domínio digitado já nasce comprovado, sem registro TXT no DNS.
        $this->assertTrue($domain->isVerified());

        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 2, 'radar' => $watch->id]), ['empresa' => 'Acme Conta', 'marcas' => ['AcmeConta']]);
        $this->actingAs($user)->get(route('alerts.lookalikes.interview', ['passo' => 3, 'radar' => $watch->id]))
            ->assertOk()->assertSee('Ativar o Radar')
            ->assertDontSee('registro TXT')->assertDontSee('secmon-verify');

        $this->actingAs($user)->post(route('alerts.lookalikes.interview.activate', ['radar' => $watch->id]), ['consent' => '1'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Radar ativado'));
        $watch->refresh();
        $this->assertSame('active', $watch->status, 'comprovado na hora, o Radar já ativa e varre');
        $this->assertSame(MonitoredDomain::RADAR_CONSENT, $domain->refresh()->consent_version);
        $this->assertTrue($domain->allowsReferenceCapture());
        $this->assertFalse($domain->canDiscover(), 'domínio do Radar nunca passa pela descoberta de tecnologias');
        Queue::assertPushed(ScanLookalikes::class, fn (ScanLookalikes $job) => $job->watchId === $watch->id);

        // A página do Radar não mostra mais nenhum quadro de posse.
        $this->actingAs($user)->get(route('alerts.lookalikes'))->assertOk()->assertDontSee('Comprove que')->assertDontSee('registro TXT');
    }

    public function test_rules_of_the_interview(): void
    {
        $user = $this->client();
        $stranger = $this->client();

        // Domínio inválido e passo ainda não alcançado.
        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 1]), ['origem' => 'outro', 'outro' => '10.0.0.1'])
            ->assertSessionHasErrors('outro');
        $this->actingAs($user)->get(route('alerts.lookalikes.interview', ['passo' => 3]))->assertRedirect(route('alerts.lookalikes.interview', ['passo' => 1]));
        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 2]), ['empresa' => 'X'])->assertNotFound();

        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 1]), ['origem' => 'outro', 'outro' => 'acmepay.com.br']);
        $watch = LookalikeWatch::query()->sole();

        // Radar de outro cliente: 404 em tudo.
        $this->actingAs($stranger)->get(route('alerts.lookalikes.interview', ['passo' => 1, 'radar' => $watch->id]))->assertNotFound();
        $this->actingAs($stranger)->post(route('alerts.lookalikes.interview.activate', ['radar' => $watch->id]))->assertNotFound();
        $this->actingAs($stranger)->delete(route('alerts.lookalikes.destroy', ['radar' => $watch->id]))->assertNotFound();

        // Ativar antes do passo 2: 404. O mesmo domínio em outro Radar: recusado.
        $this->actingAs($user)->post(route('alerts.lookalikes.interview.activate', ['radar' => $watch->id]))->assertNotFound();
        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 1]), ['origem' => 'outro', 'outro' => 'acmepay.com.br'])
            ->assertSessionHasErrors('outro');
        $this->assertSame(1, LookalikeWatch::query()->count());

        // Trocar o domínio no rascunho troca a linha do domínio (a antiga sai).
        $old = $watch->domain_id;
        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 1, 'radar' => $watch->id]), ['origem' => 'outro', 'outro' => 'acmepay.net']);
        $this->assertSame('acmepay.net', $watch->refresh()->domain->domain);
        $this->assertNull(MonitoredDomain::query()->find($old));

        // Ativado, o domínio não muda mais.
        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 2, 'radar' => $watch->id]), ['empresa' => 'Acme']);
        $this->actingAs($user)->post(route('alerts.lookalikes.interview.activate', ['radar' => $watch->id]));
        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 1, 'radar' => $watch->id]), ['origem' => 'outro', 'outro' => 'acmepay.org'])
            ->assertSessionHas('error');
        $this->assertSame('acmepay.net', $watch->refresh()->domain->domain);

        // Excluir leva o domínio e os achados.
        $this->lookalike($watch, 'acmepay-login.net');
        $domainId = $watch->domain_id;
        $this->actingAs($user)->delete(route('alerts.lookalikes.destroy'))->assertRedirect(route('alerts.lookalikes'));
        $this->assertSame(0, LookalikeWatch::query()->count());
        $this->assertNull(MonitoredDomain::query()->find($domainId));
    }

    public function test_revoking_the_consent_removes_the_client_site_prints(): void
    {
        $watch = $this->watch($this->verifiedDomain(consent: MonitoredDomain::RADAR_CONSENT), [
            'reference' => ['pages' => [['url' => 'https://acmepay.com.br/', 'title' => 'Início', 'shots' => ['desktop' => 'ref/1-desktop.jpg']]]],
            'reference_at' => now(),
        ]);
        $file = $this->fakeShot('ref/1-desktop.jpg');

        $this->actingAs($watch->user)->post(route('alerts.lookalikes.interview.activate', ['radar' => $watch->id]), [])
            ->assertSessionHas('status', 'Radar salvo.');

        $this->assertNull($watch->domain->refresh()->consent_at);
        $this->assertSame([[], 'no_consent'], [$watch->refresh()->reference, $watch->reference_status]);
        $this->assertFileDoesNotExist($file);
    }
}
