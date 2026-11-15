<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Jobs\SendSlackMessage;
use App\Models\AlertMatch;
use App\Models\Company;
use App\Models\GithubFinding;
use App\Models\LookalikeAlert;
use App\Models\LookalikeDomain;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\AlertCenter;
use App\Services\Alerts\Slack\SlackClient;
use App\Services\Auth\ReauthCodes;
use App\Services\Company\Companies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * E2e (D7, §4.13; DESENHO-5.2 §2.2): o envio e os links das mensagens param quando a empresa está suspensa ou
 * desligada, a conta foi desativada ou já não vê o item; o código de segurança nunca vai a canal de conta de empresa.
 * A conta pessoal segue como sempre.
 */
class DeliveryGateTest extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private User $bia;

    private Sector $ti;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->bia = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bia, $this->ti, manager: true);
    }

    private function slack(User $user, array $attributes = []): NotificationChannel
    {
        return NotificationChannel::query()->create($attributes + [
            'user_id' => $user->id, 'type' => 'slack', 'method' => 'webhook', 'status' => 'active',
            'webhook_url' => 'https://hooks.slack.com/services/T000/B000/abcdefghijklmnopqrstuvwx',
        ]);
    }

    /** Uma entrega do item pelo canal, já rodada. */
    private function deliver(NotificationChannel $channel, array $item): NotificationDelivery
    {
        $delivery = NotificationDelivery::query()->create($item + ['channel_id' => $channel->id, 'user_id' => $channel->user_id, 'kind' => 'realtime', 'payload' => ['text' => 'alerta']]);
        // Cada entrega é um job: a fila renova a visibilidade a cada um (aqui o job roda direto).
        $this->refreshVisibility();
        (new SendSlackMessage($delivery->id))->handle(app(SlackClient::class));

        return $delivery->refresh();
    }

    /** @return array{AlertMatch, LookalikeAlert, GithubFinding} os itens de um setor de TI criados pela Bia */
    private function items(?User $owner = null, ?Company $company = null, ?Sector $sector = null): array
    {
        $owner ??= $this->bia;
        $children = $this->childrenOf($this->profileOf($owner, $company, $sector), $this->watchOf($owner, $company, $sector), $this->monitorOf($owner, $company, $sector));

        return [$children[AlertMatch::class], $children[LookalikeAlert::class], $children[GithubFinding::class]];
    }

    public function test_nothing_goes_out_while_the_company_is_suspended_and_it_comes_back_after(): void
    {
        [$match, $alert, $finding] = $this->items(company: $this->acme, sector: $this->ti);
        $channel = $this->slack($this->bia);
        app(Companies::class)->suspend($this->acme);

        $news = $this->deliver($channel, ['match_id' => $match->id]);
        $radar = $this->deliver($channel, ['meta' => ['lookalike_alert_id' => $alert->id]]);
        $github = $this->deliver($channel, ['meta' => ['github_finding_id' => $finding->id]]);
        $test = $this->deliver($channel, ['kind' => 'test']);

        foreach ([$news, $radar, $github, $test] as $delivery) {
            $this->assertSame(['skipped', 'empresa suspensa: envios pausados', 0], [$delivery->status, $delivery->error, $delivery->attempts]);
        }
        $this->assertSame(['skipped', 'company_paused'], [$match->fresh()->delivery_status, $match->fresh()->delivery_note]);
        $this->assertSame('envio pausado: a empresa está suspensa', $match->fresh()->deliveryLabel());
        $this->assertSame(['skipped', 'company_paused'], [$alert->fresh()->status, $alert->fresh()->note]);
        $this->assertSame(['skipped', 'company_paused'], [$finding->fresh()->status, $finding->fresh()->note]);
        Http::assertNothingSent();

        // A chave da empresa desligada também pausa; reativada, sai.
        app(Companies::class)->resume($this->acme);
        app(Companies::class)->disable($this->acme);
        $this->assertSame('skipped', $this->deliver($channel, ['match_id' => $match->id])->status);
        app(Companies::class)->enable($this->acme);
        $this->assertSame('sent', $this->deliver($channel, ['match_id' => $match->id])->status);
        Http::assertSentCount(1);
    }

    public function test_an_account_that_left_the_sector_or_was_deactivated_receives_nothing_of_it(): void
    {
        [$match] = $this->items(company: $this->acme, sector: $this->ti);
        [$general] = $this->items(company: $this->acme);
        $channel = $this->slack($this->bia);

        $this->ti->users()->detach($this->bia->id);
        $this->assertSame(['skipped', 'quem recebe já não vê este alerta'], [$this->deliver($channel, ['match_id' => $match->id])->status, $match->fresh()->deliveryLabel()]);
        // O que é da empresa toda ela ainda vê.
        $this->assertSame('sent', $this->deliver($channel, ['match_id' => $general->id])->status);
        // Num envio agrupado, um item que ela não vê segura o envio inteiro.
        $this->assertSame('skipped', $this->deliver($channel, ['kind' => 'grouped', 'meta' => ['match_ids' => [$general->id, $match->id]]])->status);

        $this->memberRow($this->bia)->forceFill(['deactivated_at' => now()])->save();
        $this->assertSame('skipped', $this->deliver($channel, ['match_id' => $general->id])->status);
        $this->assertSame(['skipped', 'quem recebe já não vê este alerta'], [($digest = $this->deliver($channel, ['kind' => 'digest']))->status, $digest->error]);
    }

    public function test_the_personal_account_is_untouched(): void
    {
        $pedro = User::factory()->create();
        [$match, $alert, $finding] = $this->items($pedro);
        $channel = $this->slack($pedro);
        app(Companies::class)->suspend($this->acme);

        $this->assertSame('sent', $this->deliver($channel, ['match_id' => $match->id])->status);
        $this->assertSame('sent', $this->deliver($channel, ['meta' => ['lookalike_alert_id' => $alert->id]])->status);
        $this->assertSame('sent', $this->deliver($channel, ['meta' => ['github_finding_id' => $finding->id]])->status);
        $this->assertSame('sent', $this->deliver($channel, ['kind' => 'test'])->status);
    }

    /** @return array<string, array{string, string}> os links de cada item: [método, url] */
    private function links(AlertMatch $match, LookalikeAlert $alert, GithubFinding $finding): array
    {
        $signed = fn (string $name, array $params) => URL::temporarySignedRoute($name, now()->addDay(), $params);
        $lookalike = $alert->lookalike;

        return [
            'relevante' => ['get', $signed('alerts.feedback.signed.relevant', ['match' => $match->id])],
            'não relevante' => ['get', $signed('alerts.feedback.signed', ['match' => $match->id])],
            'motivo do alerta' => ['post', $signed('alerts.feedback.signed.note', ['match' => $match->id])],
            'golpe' => ['get', $signed('lookalikes.verdict.signed', ['lookalike' => $lookalike->id, 'acao' => 'golpe'])],
            'motivo do Radar' => ['post', $signed('lookalikes.verdict.signed.note', ['lookalike' => $lookalike->id])],
            'GitHub' => ['get', $signed('github.feedback.signed', ['finding' => $finding->id, 'acao' => 'relevante'])],
            'motivo do GitHub' => ['post', $signed('github.feedback.signed.note', ['finding' => $finding->id])],
        ];
    }

    public function test_message_links_go_inactive_and_record_nothing(): void
    {
        // São dezenas de visitas do mesmo IP: o limite por minuto dos links não é o assunto aqui.
        $this->withoutMiddleware(ThrottleRequests::class);
        [$match, $alert, $finding] = $this->items(company: $this->acme, sector: $this->ti);
        $links = $this->links($match, $alert, $finding);
        $photo = URL::temporarySignedRoute('lookalikes.photo', now()->addDay(), ['alert' => $alert->id]);

        $cases = [
            'suspensa' => [fn () => app(Companies::class)->suspend($this->acme), fn () => app(Companies::class)->resume($this->acme)],
            'chave desligada' => [fn () => app(Companies::class)->disable($this->acme), fn () => app(Companies::class)->enable($this->acme)],
        ];

        foreach ($cases as $case => [$cut, $restore]) {
            $cut();

            foreach ($links as $name => [$method, $url]) {
                $this->{$method}($url, ['feedback' => 'relevant', 'note' => 'é nosso'])
                    ->assertStatus(410)->assertSee('Este link não está mais ativo.')->assertHeader('X-Robots-Tag', 'noindex');
            }
            $this->get($photo)->assertNotFound()->assertDontSee('link');

            $this->assertNull($match->fresh()->feedback, $case);
            $this->assertNull(LookalikeDomain::query()->find($alert->lookalike_id)->verdict, $case);
            $this->assertNull($finding->fresh()->feedback, $case);

            $restore();
        }

        // De volta: o link vale e grava o voto — mesmo com quem criou fora do setor e removida (E5b1): o link é de quem
        // lê o canal do setor, e quem criou o item não importa mais.
        $this->ti->users()->detach($this->bia->id);
        $this->memberRow($this->bia)->forceFill(['deactivated_at' => now()])->save();
        $this->get($links['relevante'][1])->assertOk();
        $this->assertSame('relevant', $match->fresh()->feedback);
    }

    public function test_links_of_the_personal_account_still_work_with_any_company_suspended(): void
    {
        [$match, $alert, $finding] = $this->items(User::factory()->create());
        app(Companies::class)->suspend($this->acme);

        $this->get($this->links($match, $alert, $finding)['golpe'][1])->assertOk();
        $this->assertSame('threat', LookalikeDomain::query()->find($alert->lookalike_id)->verdict);
        $this->get($this->links($match, $alert, $finding)['GitHub'][1])->assertOk();
        $this->assertSame('relevant', $finding->fresh()->feedback);
    }

    public function test_security_codes_never_go_to_a_company_account_channel(): void
    {
        $pedro = User::factory()->create();
        $private = ['type' => 'telegram', 'method' => 'private', 'status' => 'active', 'telegram_chat_id' => '123'];
        NotificationChannel::query()->create($private + ['user_id' => $pedro->id]);
        NotificationChannel::query()->create($private + ['user_id' => $this->bia->id, 'telegram_chat_id' => '456']);
        $codes = app(ReauthCodes::class);
        $session = $this->app['session']->driver();

        $codes->rememberDestinations($pedro, $session);
        $this->assertCount(1, $codes->channels($pedro, $session));

        $codes->rememberDestinations($this->bia, $session);
        $this->assertCount(0, $codes->channels($this->bia, $session));
    }

    public function test_the_notifiers_sources_are_what_the_account_created_and_still_sees(): void
    {
        $this->profileOf($this->bia, $this->acme, $this->ti);
        $this->watchOf($this->bia, $this->acme, $this->ti);
        $this->monitorOf($this->bia, $this->acme, $this->ti);
        // Da empresa toda, criado pela admin: a Bia vê, mas não vai para os canais dela até a E5b.
        $this->profileOf($this->ana, $this->acme);
        $caio = $this->companyAccount($this->acme);
        $this->joinSector($caio, $this->ti);

        $sources = fn (User $user) => array_intersect_key(app(AlertCenter::class)->page($user)['sources'], array_flip(['news', 'radar', 'github']));

        $this->assertSame(['news' => true, 'radar' => true, 'github' => true], $sources($this->bia));
        $this->assertSame(['news' => false, 'radar' => false, 'github' => false], $sources($caio));
        $this->ti->users()->detach($this->bia->id);
        $this->assertSame(['news' => false, 'radar' => false, 'github' => false], $sources($this->bia));
    }
}
