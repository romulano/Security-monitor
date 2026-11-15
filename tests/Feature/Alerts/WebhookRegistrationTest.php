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

    public function test_an_unproven_domain_waits_for_the_txt_and_nothing_is_sent(): void
    {
        $user = $this->client();
        $this->actingAs($user);

        $this->store([])->assertRedirect();
        $webhook = $user->webhooks()->sole();

        // A cópia da prova nasce no domínio principal, pendente: nada sai, nem o teste.
        $this->assertSame(['pending', 'cliente.com.br', null], [$webhook->status, $webhook->domain->domain, $webhook->domain->verified_at]);
        $this->get(route('alerts.webhooks.show', $webhook))
            ->assertOk()
            ->assertSee('Comprove que o domínio é seu')
            ->assertSee($webhook->domain->txtValue())
            ->assertDontSee(route('alerts.webhooks.test', $webhook));

        $this->post(route('alerts.webhooks.test', $webhook))->assertSessionHas('error', 'Comprove o domínio antes de enviar o teste: até lá, o Security Monitor não chama o endereço.');

        // Um alerta que casa não vai para ele (o webhook não conta como canal que recebe).
        $this->profile($user, [], ['nginx']);
        $case = $this->newsCase('Nginx RCE', 'critical', ['affected_products' => ['nginx 1.24']]);
        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

        Http::assertNothingSent();
        $this->assertSame(0, NotificationDelivery::query()->count());
        $match = AlertMatch::query()->sole();
        $this->assertSame(['skipped', 'no_channel'], [$match->delivery_status, $match->delivery_note]);
    }

    public function test_the_verify_button_finds_the_txt_and_turns_the_webhook_on(): void
    {
        $user = $this->client();
        $this->actingAs($user)->store([]);
        $webhook = $user->webhooks()->sole();

        // Sem o TXT: recusa dizendo o que falta.
        $this->post(route('alerts.webhooks.verify', $webhook))->assertSessionHas('error');
        $this->assertSame('pending', $webhook->refresh()->status);

        $this->dns->set('cliente.com.br', 'txt', [$webhook->domain->txtValue()]);
        $this->post(route('alerts.webhooks.verify', $webhook))->assertSessionHas('status');

        $this->assertSame('active', $webhook->refresh()->status);
        $this->assertNotNull($webhook->domain->verified_at);

        $this->post(route('alerts.webhooks.test', $webhook))->assertSessionHas('status');
        Http::assertSent(fn ($request) => $request->url() === self::HOOK && $request->header('Security-Monitor-Event')[0] === 'test');
    }

    public function test_a_txt_reached_through_a_cname_does_not_prove_the_domain(): void
    {
        $user = $this->client();
        $this->actingAs($user)->store([]);
        $webhook = $user->webhooks()->sole();

        $this->dns->set('cliente.com.br', 'cname', ['atacante.example']);
        $this->dns->set('atacante.example', 'txt', [$webhook->domain->txtValue()]);

        $this->post(route('alerts.webhooks.verify', $webhook))->assertSessionHas('error');
        $this->assertSame('pending', $webhook->refresh()->status);
    }

    public function test_a_domain_proven_in_another_service_is_reused_with_the_same_token(): void
    {
        $user = $this->client();
        $radar = $this->proven($user, 'cliente.com.br', 'tokdoradar');

        $this->actingAs($user)->store([])->assertSessionHas('status', 'Webhook cadastrado com o domínio que você já comprovou. Mande um teste para conferir o seu servidor.');
        $webhook = $user->webhooks()->sole();

        // Ativo na hora, com a cópia própria da prova (o mesmo token e a mesma data), sem perguntar ao DNS.
        $this->assertSame('active', $webhook->status);
        $this->assertNotSame($radar->id, $webhook->domain_id);
        $this->assertSame(['cliente.com.br', 'tokdoradar'], [$webhook->domain->domain, $webhook->domain->verify_token]);
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
        $this->proven($user, 'loja.cliente.com.br', 'tokdaloja');
        $this->actingAs($user);

        foreach (['https://loja.cliente.com.br/hook' => 'active', 'https://api.loja.cliente.com.br/hook' => 'active', 'https://api.cliente.com.br/hook' => 'pending'] as $url => $status) {
            $this->store(['nome' => $url, 'url' => $url])->assertSessionHasNoErrors();
            $this->assertSame($status, NotificationChannel::query()->where('name', $url)->sole()->status, $url);
        }

        // O de cima pede o TXT no domínio principal, com o token que a conta já usa (um registro serve aos dois serviços).
        $pending = NotificationChannel::query()->where('name', 'https://api.cliente.com.br/hook')->sole();
        $this->assertSame(['cliente.com.br', 'tokdaloja'], [$pending->domain->domain, $pending->domain->verify_token]);
    }

    public function test_another_accounts_proof_does_not_count(): void
    {
        $this->proven($this->client(), 'cliente.com.br');
        $user = $this->client();

        $this->actingAs($user)->store([]);

        $this->assertSame('pending', $user->webhooks()->sole()->status);
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

    public function test_five_webhooks_at_most_with_names_urls_and_secrets_of_their_own(): void
    {
        $user = $this->client();
        $this->proven($user);
        $this->actingAs($user);

        foreach (range(1, 5) as $i) {
            $this->store(['nome' => "Hook {$i}", 'url' => self::HOOK."/{$i}"])->assertSessionHasNoErrors();
        }

        $this->store(['nome' => 'Hook 6', 'url' => self::HOOK.'/6'])->assertSessionHas('error', 'Você chegou ao limite de 5 webhooks. Exclua um para cadastrar outro.');
        $this->assertSame(5, $user->webhooks()->count());

        $user->webhooks()->where('name', 'Hook 5')->sole()->delete();
        $this->store(['nome' => 'hook 1', 'url' => self::HOOK.'/9'])->assertSessionHasErrors(['nome' => 'Você já tem um webhook com esse nome.']);
        $this->store(['nome' => 'Outro', 'url' => self::HOOK.'/1'])->assertSessionHasErrors(['url' => 'Essa URL já está no Webhook Hook 1.']);

        $taken = (string) $user->webhooks()->first()->webhook_secret;
        $this->store(['nome' => 'Outro', 'url' => self::HOOK.'/7', 'autenticacao' => 'escolher', 'segredo' => $taken])
            ->assertSessionHasErrors(['segredo' => 'Esse valor já é o segredo de outro webhook seu: cada webhook tem o próprio.']);
    }

    public function test_a_new_url_on_an_unproven_host_goes_back_to_pending(): void
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

        $this->assertSame(['pending', 'siem.outra.com.br', 'outra.com.br'], [$webhook->status, $webhook->webhook_host, $webhook->domain->domain]);
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
