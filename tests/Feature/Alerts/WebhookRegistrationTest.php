<?php

namespace Tests\Feature\Alerts;

use App\Jobs\MatchCaseToProfiles;
use App\Models\AlertMatch;
use App\Models\MonitoredDomain;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Services\Alerts\ProfileIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Webhook: cadastrar só em domínio comprovado (a prova de outro serviço vale), mudar, limitar e excluir. */
class WebhookRegistrationTest extends TestCase
{
    use RefreshDatabase, WebhookTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
        $this->fakeDns();
        Http::fake(['https://hooks.cliente.com.br/*' => Http::response('', 204)]);
    }

    private function store(array $form): TestResponse
    {
        return $this->post(route('alerts.webhooks.store'), $form + ['nome' => 'Produção', 'url' => self::HOOK, 'autenticacao' => 'gerar']);
    }

    public function test_a_new_domain_is_born_verified_and_the_webhook_can_send_right_away(): void
    {
        $user = $this->client();
        $this->actingAs($user);

        $this->store([])->assertRedirect();
        $webhook = $user->webhooks()->sole();

        // Instalação de um dono só: a cópia da prova nasce no domínio principal, já comprovada.
        $this->assertSame('active', $webhook->status);
        $this->assertSame('cliente.com.br', $webhook->domain->domain);
        $this->assertTrue($webhook->domain->isVerified());
        $this->get(route('alerts.webhooks.show', $webhook))->assertOk()->assertSee(route('alerts.webhooks.test', $webhook));

        $this->post(route('alerts.webhooks.test', $webhook))->assertSessionHas('status');
        Http::assertSent(fn ($request) => $request->url() === self::HOOK && $request->header('Security-Monitor-Event')[0] === 'test');

        // Um alerta que casa vai para ele.
        Http::fake(['https://hooks.cliente.com.br/*' => Http::response('', 204)]);
        $this->profile($user, [], ['nginx']);
        $case = $this->newsCase('Nginx RCE', 'critical', ['affected_products' => ['nginx 1.24']]);
        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

        $this->assertSame(1, NotificationDelivery::query()->where('kind', '!=', 'test')->count());
        $match = AlertMatch::query()->sole();
        $this->assertNotSame('no_channel', $match->delivery_note);
    }

    public function test_a_domain_proven_in_another_service_is_reused_without_asking_the_dns(): void
    {
        $user = $this->client();
        $radar = $this->proven($user, 'cliente.com.br', 'tokdoradar');

        $this->actingAs($user)->store([])->assertSessionHas('status', 'Webhook cadastrado. Mande um teste para conferir o seu servidor.');
        $webhook = $user->webhooks()->sole();

        // Ativo na hora, com a cópia própria da prova (a mesma data), sem perguntar ao DNS.
        $this->assertSame('active', $webhook->status);
        $this->assertNotSame($radar->id, $webhook->domain_id);
        $this->assertSame('cliente.com.br', $webhook->domain->domain);
        $this->assertTrue($webhook->domain->verified_at->equalTo($radar->verified_at));
        $this->assertSame([], array_filter($this->dns->asked, fn ($q) => str_starts_with($q, 'txt')));

        // Excluir o Radar não derruba o webhook; excluir o webhook não leva a prova do Radar.
        $radar->delete();
        $this->assertTrue($webhook->refresh()->domain->isVerified());
        $copy = $webhook->domain_id;
        $this->delete(route('alerts.webhooks.destroy', $webhook))->assertRedirect(route('alerts.notifiers'));
        $this->assertNull(NotificationChannel::query()->find($webhook->id));
        $this->assertNull(MonitoredDomain::query()->find($copy));
    }

    public function test_the_proof_covers_the_domain_and_its_subdomains_only(): void
    {
        $user = $this->client();
        $loja = $this->proven($user, 'loja.cliente.com.br', 'tokdaloja');
        $this->actingAs($user);

        foreach (['https://loja.cliente.com.br/hook' => 'loja.cliente.com.br', 'https://api.loja.cliente.com.br/hook' => 'loja.cliente.com.br', 'https://api.cliente.com.br/hook' => 'cliente.com.br'] as $url => $domain) {
            $this->store(['nome' => $url, 'url' => $url])->assertSessionHasNoErrors();
            $channel = NotificationChannel::query()->where('name', $url)->sole();
            // Todos ativos na hora (instalação de um dono só); só o domínio da cópia muda.
            $this->assertSame('active', $channel->status, $url);
            $this->assertSame($domain, $channel->domain->domain, $url);
        }

        // "api.cliente.com.br" não é subdomínio de "loja.cliente.com.br": não reaproveita a prova dela,
        // ganha uma cópia própria no domínio principal (que nasce comprovada do mesmo jeito).
        $own = NotificationChannel::query()->where('name', 'https://api.cliente.com.br/hook')->sole();
        $this->assertNotSame($loja->id, $own->domain_id);
    }

    public function test_another_accounts_proof_does_not_count_but_the_webhook_is_active_anyway(): void
    {
        $other = $this->proven($this->client(), 'cliente.com.br');
        $user = $this->client();

        $this->actingAs($user)->store([]);
        $webhook = $user->webhooks()->sole();

        // Instalação de um dono só: a cópia nasce comprovada por conta própria, nunca pela prova de outra conta.
        $this->assertSame('active', $webhook->status);
        $this->assertNotSame($other->id, $webhook->domain_id);
    }

    public function test_unsafe_urls_are_refused_before_anything_is_saved(): void
    {
        $user = $this->client();
        $this->proven($user);
        $this->actingAs($user);

        foreach ([
            'http://hooks.cliente.com.br/secmon', 'https://10.0.0.1/secmon', 'https://[::1]/secmon', 'https://localhost/secmon',
            'https://cofre.internal/secmon', 'https://hooks.cliente.com.br:8443/secmon', 'https://user:senha@hooks.cliente.com.br/secmon',
            'https://example.com/alertas',
        ] as $url) {
            $this->store(['url' => $url])->assertSessionHasErrors('url');
        }

        $this->assertSame(0, $user->webhooks()->count());
        Http::assertNothingSent();
    }

    public function test_webhooks_have_names_urls_and_secrets_of_their_own(): void
    {
        $user = $this->client();
        $this->proven($user);
        $this->actingAs($user);

        // Sem limite de webhooks por conta: a conta cadastra quantos quiser.
        foreach (range(1, 6) as $i) {
            $this->store(['nome' => "Hook {$i}", 'url' => self::HOOK."/{$i}"])->assertSessionHasNoErrors();
        }

        $this->assertSame(6, $user->webhooks()->count());

        $user->webhooks()->where('name', 'Hook 5')->sole()->delete();
        $this->store(['nome' => 'hook 1', 'url' => self::HOOK.'/9'])->assertSessionHasErrors(['nome' => 'Você já tem um webhook com esse nome.']);
        $this->store(['nome' => 'Outro', 'url' => self::HOOK.'/1'])->assertSessionHasErrors(['url' => 'Essa URL já está no Webhook Hook 1.']);

        $taken = (string) $user->webhooks()->first()->webhook_secret;
        $this->store(['nome' => 'Outro', 'url' => self::HOOK.'/7', 'autenticacao' => 'escolher', 'segredo' => $taken])
            ->assertSessionHasErrors(['segredo' => 'Esse valor já é o segredo de outro webhook seu: cada webhook tem o próprio.']);
    }

    public function test_a_new_url_on_a_new_host_gets_its_own_copy_active_right_away(): void
    {
        $user = $this->client();
        $this->proven($user);
        $this->actingAs($user)->store([]);
        $webhook = $user->webhooks()->sole();
        $copy = $webhook->domain_id;

        // Em branco, a URL fica; o nome muda.
        $this->put(route('alerts.webhooks.endpoint', $webhook), ['nome' => 'SIEM', 'url' => ''])->assertSessionHasNoErrors();
        $this->assertSame(['SIEM', self::HOOK, 'active', $copy], [$webhook->refresh()->name, $webhook->webhook_url, $webhook->status, $webhook->domain_id]);

        $this->put(route('alerts.webhooks.endpoint', $webhook), ['nome' => 'SIEM', 'url' => 'https://siem.outra.com.br/in'])->assertSessionHasNoErrors();
        $webhook->refresh();

        // Instalação de um dono só: a cópia antiga some, e a nova já nasce comprovada.
        $this->assertSame(['active', 'siem.outra.com.br', 'outra.com.br'], [$webhook->status, $webhook->webhook_host, $webhook->domain->domain]);
        $this->assertNull(MonitoredDomain::query()->find($copy));
    }

    public function test_only_the_owner_reaches_the_webhook(): void
    {
        $owner = $this->client();
        $webhook = $this->webhook($owner);
        $this->actingAs($this->client());

        $this->get(route('alerts.webhooks.show', $webhook))->assertNotFound();
        $this->put(route('alerts.webhooks.endpoint', $webhook), ['nome' => 'x'])->assertNotFound();
        $this->put(route('alerts.webhooks.secret', $webhook), ['autenticacao' => 'nenhuma'])->assertNotFound();
        $this->post(route('alerts.webhooks.test', $webhook))->assertNotFound();
        $this->delete(route('alerts.webhooks.destroy', $webhook))->assertNotFound();

        $this->assertTrue($webhook->refresh()->signs());
        Http::assertNothingSent();
    }

    public function test_the_secret_is_shown_once_encrypted_on_the_way_and_at_rest(): void
    {
        $user = $this->client();
        $this->proven($user);

        $this->actingAs($user)->store([]);
        $webhook = $user->webhooks()->sole();
        $secret = (string) $webhook->webhook_secret;

        $this->assertMatchesRegularExpression('/^secmon_whsec_[A-Za-z0-9_-]{43}$/', $secret);
        // No banco e na sessão (que fica no banco), só criptografado.
        $raw = DB::table('notification_channels')->where('id', $webhook->id)->value('webhook_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertSame($secret, Crypt::decryptString($raw));
        $flash = session('webhook_secret');
        $this->assertStringNotContainsString($secret, json_encode($flash));
        $this->assertSame($secret, Crypt::decryptString($flash['value']));

        // A página logo depois mostra o segredo inteiro; a seguinte, só o começo e o fim.
        $this->get(route('alerts.webhooks.show', $webhook))->assertSee($secret)->assertSee('Copie o segredo agora');
        $this->get(route('alerts.webhooks.show', $webhook))->assertDontSee($secret)->assertSee('secmon_whsec_…'.substr($secret, -4));
    }

    public function test_a_chosen_secret_must_be_long_and_varied(): void
    {
        $user = $this->client();
        $this->proven($user);
        $this->actingAs($user);

        $this->store(['autenticacao' => 'escolher', 'segredo' => 'curto'])->assertSessionHasErrors('segredo');
        $this->store(['autenticacao' => 'escolher', 'segredo' => str_repeat('ab', 20)])->assertSessionHasErrors('segredo');
        $this->store(['autenticacao' => 'escolher', 'segredo' => str_repeat('"aspas e espaço" ', 3)])->assertSessionHasErrors('segredo');
        $this->store(['autenticacao' => 'escolher'])->assertSessionHasErrors('segredo');

        $chosen = 'Meu-Segredo_de.32+caracteres/ou=mais~2026';
        $this->store(['autenticacao' => 'escolher', 'segredo' => $chosen])->assertSessionHasNoErrors();

        $this->assertSame($chosen, $user->webhooks()->sole()->webhook_secret);
        $this->assertNull(session('webhook_secret'));
    }

    public function test_notifiers_list_the_webhook_and_offer_another(): void
    {
        $user = $this->client();
        $webhook = $this->webhook($user, ['name' => 'Produção']);

        $this->actingAs($user)->get(route('alerts.notifiers'))
            ->assertOk()
            ->assertSee('Webhook Produção')
            ->assertSee('hooks.cliente.com.br')
            ->assertSee('Outro webhook')
            ->assertSee(route('alerts.webhooks.show', $webhook));

        $this->get(route('alerts.webhooks.create'))->assertOk()->assertSee('cliente.com.br')->assertSee('Security-Monitor-Signature');
    }
}
