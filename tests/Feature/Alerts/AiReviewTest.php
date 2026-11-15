<?php

namespace Tests\Feature\Alerts;

use App\Jobs\MatchCaseToProfiles;
use App\Jobs\ReviewAlertMatches;
use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Services\Alerts\AlertReviewer;
use App\Services\Alerts\ProfileIndex;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Parecer da IA antes do envio: aprende com as avaliações e só manda acima do corte. */
class AiReviewTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    private const HOOK = 'https://hooks.slack.com/services/T000/B000/abcdefghijklmnopqrstuvwx';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
        Settings::setMany(['alerts.ai_review_enabled' => true, 'enricher.hermes_url' => 'http://hermes.test']);
    }

    private function hermes(int $relevance, string $reason): void
    {
        Http::fake([
            'hermes.test/*' => Http::response(['choices' => [['message' => [
                'content' => "```json\n".json_encode(['relevance' => $relevance, 'reason' => $reason])."\n```",
            ]]]]),
            'hooks.slack.com/*' => Http::response('ok'),
        ]);
    }

    private function clientWithNginx(array $profile = [], array $user = []): AlertProfile
    {
        $client = $this->client($user);
        $this->slackChannel($client);

        return $this->profile($client, $profile + ['language' => 'pt'], ['nginx']);
    }

    private function alertFor(AlertProfile $profile, string $title = 'Nginx RCE explorado', array $indicators = []): AlertMatch
    {
        $case = $this->newsCase($title, 'critical', $indicators + ['affected_products' => ['nginx 1.24'], 'cves' => ['CVE-2026-1']]);

        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

        return AlertMatch::query()->where('case_id', $case->id)->where('profile_id', $profile->id)->sole();
    }

    /** Alerta antigo já avaliado pelo cliente, para servir de exemplo. */
    private function rated(AlertProfile $profile, string $title, string $feedback, array $reason): void
    {
        $case = $this->newsCase($title, 'medium');

        AlertMatch::query()->create([
            'profile_id' => $profile->id, 'case_id' => $case->id, 'kind' => 'new_case', 'criticity' => 'medium',
            'score' => $reason['weight'], 'reasons' => [$reason], 'delivery_status' => 'sent',
            'matched_at' => now()->subDays(3), 'feedback' => $feedback, 'feedback_at' => now()->subDays(2),
        ]);
    }

    private function hermesRequest(): Request
    {
        $sent = Http::recorded(fn (Request $request) => str_starts_with($request->url(), 'http://hermes.test/'));
        $this->assertCount(1, $sent);

        return $sent[0][0];
    }

    public function test_alert_above_the_cut_goes_out_with_the_opinion(): void
    {
        $this->hermes(86, 'Exploração ativa no Nginx, que está na sua stack.');
        $profile = $this->clientWithNginx();

        $match = $this->alertFor($profile);

        $this->assertSame(['sent', 86, null], [$match->delivery_status, $match->ai_relevance, $match->suppressed_reason]);
        Http::assertSent(fn (Request $request) => $request->url() === self::HOOK);

        // Selo com a certeza da IA e o painel "Motivo" com o parecer.
        $this->actingAs($profile->user)->get(route('alerts.news'))
            ->assertSeeInOrder(['IA 86%', 'Nginx RCE explorado', 'Motivo', 'Aprovado pela IA', '86%', 'Envia acima de 70%'])
            ->assertSee('Exploração ativa no Nginx, que está na sua stack.')
            ->assertSee('Nginx — produto da sua stack afetado')
            ->assertSee('popovertarget="motivo-'.$match->id.'"', false);
    }

    public function test_alert_at_the_cut_or_below_is_held_and_stays_visible(): void
    {
        // O corte é "maior que 70": 70 ainda fica segurado.
        $this->hermes(70, 'Só cita o Nginx de passagem.');
        $profile = $this->clientWithNginx();

        $match = $this->alertFor($profile, 'Guia de configuração do Nginx');

        $this->assertSame(['skipped', 'ai_low_relevance', 70], [$match->delivery_status, $match->suppressed_reason, $match->ai_relevance]);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::HOOK);

        // Mesmo sem envio, aparece em "Todos", pintado de violeta, e no filtro próprio.
        $this->actingAs($profile->user);
        $this->get(route('alerts.news'))
            ->assertSeeInOrder(['IA 70%', 'Guia de configuração do Nginx', 'Segurado pela IA', 'não enviado', 'Motivo'])
            ->assertSee('bg-violet-950/30', false)
            ->assertSee('1 segurado(s) pela IA');
        $this->get(route('alerts.news', ['filtro' => 'segurados-pela-ia']))
            ->assertSee('Guia de configuração do Nginx')
            ->assertSee('Só cita o Nginx de passagem.');
        $this->get(route('alerts.news', ['filtro' => 'na-hora']))->assertDontSee('Guia de configuração do Nginx');

        // "Era relevante": vira exemplo positivo para os próximos pareceres.
        $this->post(route('alerts.feedback', $match), ['feedback' => 'relevant'])
            ->assertSessionHas('status', 'Obrigado: a IA usa essa avaliação para decidir os próximos alertas.');
        $this->assertSame('relevant', $match->refresh()->feedback);
    }

    public function test_prompt_learns_from_ratings_without_identifying_the_client(): void
    {
        $this->hermes(90, 'Relevante.');
        $profile = $this->clientWithNginx(
            ['company_name' => 'Acme Pagamentos', 'name' => 'Acme site', 'brands' => ['AcmePay']],
            ['name' => 'Joana Souza', 'email' => 'joana@acmepagamentos.com.br'],
        );
        $this->rated($profile, 'WordPress lança editor novo', 'irrelevant', ['type' => 'mention', 'label' => 'WordPress', 'field' => 'texto', 'weight' => 40]);
        $this->rated($profile, 'Nginx explorado em massa', 'relevant', ['type' => 'stack', 'label' => 'Nginx', 'field' => 'affected_products', 'weight' => 60]);
        $this->rated($profile, 'Vazamento cita a empresa', 'relevant', ['type' => 'brand', 'label' => 'Acme Pagamentos', 'field' => 'victim_orgs', 'weight' => 100]);

        $this->alertFor($profile);

        $prompt = $this->hermesRequest()['messages'][1]['content'];
        // Decisão (uma nota): esforço leve, não o padrão do modelo.
        $this->assertSame('low', $this->hermesRequest()['reasoning_effort']);
        $this->assertMatchesRegularExpression('/rated NOT RELEVANT.*\n- \[medium\] "WordPress lança editor novo" — flagged because: "WordPress" — a stack term appears only in the text/', $prompt);
        $this->assertMatchesRegularExpression('/rated RELEVANT.*\n.*"Vazamento cita a empresa" — flagged because: their own company is mentioned \(name withheld\)/s', $prompt);
        $this->assertStringContainsString('"Nginx explorado em massa"', $prompt);
        $this->assertStringContainsString('- Technologies they use: Nginx', $prompt);
        $this->assertStringContainsString('Title: Nginx RCE explorado', $prompt);

        $body = json_encode($this->hermesRequest()->data(), JSON_UNESCAPED_UNICODE);
        foreach (['Acme', 'acme', 'Joana', 'Souza'] as $identifying) {
            $this->assertStringNotContainsString($identifying, $body);
        }
    }

    /** As avaliações são de cada alerta; só "o que já recebeu" (mesmo Slack, sem repetir a mesma falha) olha a conta toda. */
    public function test_each_alert_learns_only_from_its_own_ratings(): void
    {
        $this->hermes(90, 'Relevante.');
        $store = $this->clientWithNginx(['name' => 'Loja']);
        $blog = $this->profile($store->user, ['name' => 'Blog', 'company_name' => 'Outra Marca'], ['wordpress']);
        $this->rated($store, 'Nginx explorado em massa', 'relevant', ['type' => 'stack', 'label' => 'Nginx', 'field' => 'affected_products', 'weight' => 60]);
        $this->rated($blog, 'Plugin do WordPress sem correção', 'irrelevant', ['type' => 'stack', 'label' => 'WordPress', 'field' => 'affected_products', 'weight' => 60]);

        $this->alertFor($store);

        $prompt = $this->hermesRequest()['messages'][1]['content'];
        [, $ratings] = explode('## Alerts this client rated NOT RELEVANT', $prompt, 2);
        $this->assertStringContainsString('(none yet)', explode('## Alerts this client rated RELEVANT', $ratings, 2)[0], 'o "não relevante" do Blog não vale para a Loja');
        $this->assertStringContainsString('"Nginx explorado em massa"', explode('## Alerts this client rated RELEVANT', $ratings, 2)[1]);
        $this->assertStringNotContainsString('Plugin do WordPress sem correção', explode('## Alerts this client rated RELEVANT', $ratings, 2)[1]);
    }

    /** "Corrigida no 1.27" não atinge quem informou 1.24, e a IA só sabe disso com a versão no perfil. */
    public function test_ai_sees_the_versions_the_client_runs(): void
    {
        $this->hermes(90, 'Relevante.');
        $profile = $this->clientWithNginx(['tech_versions' => ['nginx' => '1.24', 'php' => '8.2']]);

        $this->alertFor($profile);

        $request = $this->hermesRequest();
        $this->assertStringContainsString('- Technologies they use: Nginx 1.24', $request['messages'][1]['content']);
        // Versão de tecnologia que o perfil não tem não aparece sozinha.
        $this->assertStringNotContainsString('8.2', $request['messages'][1]['content']);
        $this->assertStringContainsString('When the profile gives the version they run', $request['messages'][0]['content']);
    }

    public function test_update_of_a_case_already_sent_shows_the_ai_what_the_client_received(): void
    {
        // 21/10: cada fonte nova do php2shell saiu como alerta, seis no total.
        $this->hermes(18, 'Repete o alerta já enviado.');
        $profile = $this->clientWithNginx();
        $case = $this->newsCase('Nginx RCE explorado', 'critical', ['affected_products' => ['nginx 1.24'], 'cves' => ['CVE-2026-1']]);
        AlertMatch::query()->create([
            'profile_id' => $profile->id, 'case_id' => $case->id, 'article_id' => $case->articles()->value('id'),
            'kind' => 'new_case', 'criticity' => 'critical', 'score' => 60, 'reasons' => [['type' => 'stack', 'label' => 'Nginx']],
            'delivery_status' => 'sent', 'notified_at' => now()->subHours(5), 'matched_at' => now()->subHours(5),
        ]);
        $article = $this->article($case, ['title' => 'Outro site repete o Nginx RCE', 'is_first' => false, 'new_context' => 'Adds the CVSS score.']);

        MatchCaseToProfiles::dispatchSync($case->id, $article->id, 'case_update');

        $prompt = $this->hermesRequest()['messages'][1]['content'];
        $this->assertMatchesRegularExpression('/UPDATE of a case .*\(1 message\(s\), oldest first\):\n- \d{4}-\d\d-\d\d \d\d:\d\d UTC: "Nginx RCE explorado" — first alert\nWhat the new source adds: Adds the CVSS score\./', $prompt);
        $this->assertStringContainsString('do not score its relevance again', $this->hermesRequest()['messages'][0]['content']);

        $update = AlertMatch::query()->where('article_id', $article->id)->sole();
        $this->assertSame(['case_update', 'skipped', 'ai_low_relevance', 18], [$update->kind, $update->delivery_status, $update->suppressed_reason, $update->ai_relevance]);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::HOOK);
    }

    public function test_ai_sees_the_alerts_sent_on_other_cases_in_the_last_72_hours(): void
    {
        // O mesmo php2shell virou outro caso (título diferente, sem CVE) e saiu de novo.
        $this->hermes(90, 'Relevante.');
        $profile = $this->clientWithNginx(['brands' => ['AcmePay']]);
        // Sem stack: só o perfil acima casa com a notícia nova.
        $sibling = $this->profile($profile->user, ['name' => 'Outro site']);
        $sent = fn (AlertProfile $p, string $title, array $attributes = [], array $indicators = []) => AlertMatch::query()->create($attributes + [
            'profile_id' => $p->id, 'case_id' => $this->newsCase($title, 'critical', $indicators)->id, 'kind' => 'new_case', 'criticity' => 'critical',
            'score' => 60, 'reasons' => [['type' => 'stack', 'label' => 'Nginx']], 'delivery_status' => 'sent', 'matched_at' => now()->subHours(3),
        ]);
        $sent($profile, 'Nginx warns of emergency patches', [], ['cves' => ['CVE-2026-109618'], 'affected_products' => ['nginx 1.24']]);
        $sent($sibling, 'Enviado pelo outro alerta do cliente', ['matched_at' => now()->subHours(1)]);
        $sent($profile, 'Esperando o silêncio', ['delivery_status' => 'held', 'delivery_note' => 'quiet_hours']);
        $sent($profile, 'Antigo demais', ['matched_at' => now()->subHours(80)]);
        $sent($profile, 'Segurado pela IA', ['delivery_status' => 'skipped', 'suppressed_reason' => 'ai_low_relevance']);
        $sent($profile, 'Ainda em análise', ['delivery_status' => 'pending', 'delivery_note' => 'ai_review']);
        $sent($profile, 'Só no resumo', ['delivery_status' => 'skipped', 'delivery_note' => 'realtime_off']);
        $sent($profile, 'AcmePay tem dados vazados', ['reasons' => [['type' => 'brand', 'label' => 'AcmePay']]]);
        $sent($this->profile(), 'Alerta de outro cliente');

        $this->alertFor($profile, 'Nginx RCE explorado após vazamento');

        $prompt = $this->hermesRequest()['messages'][1]['content'];
        $this->assertStringNotContainsString('UPDATE of a case', $prompt);
        $this->assertMatchesRegularExpression('/other cases sent to this client in the last 72 hours \(most recent first\):\n'
            .'- \[critical\] [^"]+ "Enviado pelo outro alerta do cliente"\n'
            .'- \[critical\] [^"]+ "Nginx warns of emergency patches" — CVEs: CVE-2026-109618 — products: nginx 1\.24\n'
            .'- \[critical\] [^"]+ "Esperando o silêncio"\n/', $prompt);

        foreach (['Antigo demais', 'Segurado pela IA', 'Ainda em análise', 'Só no resumo', 'AcmePay', 'Alerta de outro cliente'] as $absent) {
            $this->assertStringNotContainsString($absent, $prompt);
        }
    }

    public function test_reviews_of_the_same_client_run_one_at_a_time(): void
    {
        $profile = $this->clientWithNginx();
        $sibling = $this->profile($profile->user, ['name' => 'Outro site'], ['nginx']);
        $match = fn (AlertProfile $p) => AlertMatch::query()->create([
            'profile_id' => $p->id, 'case_id' => $this->newsCase('Caso '.$p->id)->id, 'kind' => 'new_case', 'criticity' => 'high',
            'score' => 60, 'reasons' => [], 'delivery_status' => 'pending', 'delivery_note' => 'ai_review', 'matched_at' => now(),
        ])->id;
        $key = fn (array $ids) => (new ReviewAlertMatches($ids))->middleware()[0]->key;

        $this->assertSame($key([$match($profile)]), $key([$match($sibling)]));
        $this->assertNotSame($key([$match($profile)]), $key([$match($this->clientWithNginx())]));
    }

    public function test_news_citing_the_company_skips_the_ai_and_always_goes_out(): void
    {
        $this->hermes(5, 'Não deveria ser chamado.');
        $profile = $this->clientWithNginx(['include_brand_mentions' => true]);

        $match = $this->alertFor($profile, 'Acme Pagamentos tem dados vazados', ['victim_orgs' => ['Acme Pagamentos']]);

        $this->assertSame('sent', $match->delivery_status);
        $this->assertNull($match->ai_reviewed_at);
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'http://hermes.test/'));
    }

    public function test_without_an_answer_from_the_ai_the_alert_still_goes_out(): void
    {
        Http::fake(['hermes.test/*' => Http::response('bridge down', 503), 'hooks.slack.com/*' => Http::response('ok')]);
        $profile = $this->clientWithNginx();

        $match = $this->alertFor($profile);

        $this->assertSame(['sent', null], [$match->delivery_status, $match->ai_relevance]);
        $this->assertStringStartsWith('Sem parecer da IA (hermes HTTP 503', $match->ai_reason);
        Http::assertSent(fn (Request $request) => $request->url() === self::HOOK);
    }

    public function test_one_unusable_answer_is_retried_before_giving_up(): void
    {
        Http::fake([
            'hermes.test/*' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => 'Desculpe, não consegui avaliar.']]]])
                ->push(['choices' => [['message' => ['content' => '{"relevance": 12, "reason": "Boletim genérico."}']]]]),
            'hooks.slack.com/*' => Http::response('ok'),
        ]);

        $match = $this->alertFor($this->clientWithNginx());

        $this->assertSame(['skipped', 'ai_low_relevance', 12], [$match->delivery_status, $match->suppressed_reason, $match->ai_relevance]);
        Http::assertSentCount(2);
    }

    public function test_review_switched_off_sends_as_before(): void
    {
        Settings::set('alerts.ai_review_enabled', false);
        $this->hermes(5, 'Não deveria ser chamado.');

        $match = $this->alertFor($this->clientWithNginx());

        $this->assertSame('sent', $match->delivery_status);
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'http://hermes.test/'));
    }

    /** Alerta que já existia antes do juiz, no estado de entrega indicado. */
    private function existing(AlertProfile $profile, string $title, array $attributes = [], string $reason = 'stack'): AlertMatch
    {
        $case = $this->newsCase($title);

        return AlertMatch::query()->create($attributes + [
            'profile_id' => $profile->id, 'case_id' => $case->id, 'kind' => 'new_case', 'criticity' => 'high', 'score' => 60,
            'reasons' => [['type' => $reason, 'label' => $reason === 'brand' ? 'Acme Pagamentos' : 'Nginx', 'field' => 'affected_products', 'weight' => 60]],
            'delivery_status' => 'sent', 'notified_at' => now()->subDay(), 'matched_at' => now()->subDay(),
        ]);
    }

    public function test_command_reviews_existing_alerts_without_sending_anything(): void
    {
        Http::fake([
            'hermes.test/*' => fn (Request $request) => Http::response(['choices' => [['message' => ['content' => json_encode(
                str_contains($request['messages'][1]['content'], 'Title: Guia')
                    ? ['relevance' => 15, 'reason' => 'Só cita o Nginx.']
                    : ['relevance' => 92, 'reason' => 'Falha explorada no Nginx.'],
            )]]]]),
            'hooks.slack.com/*' => Http::response('ok'),
        ]);
        $profile = $this->clientWithNginx();
        $good = $this->existing($profile, 'Nginx RCE explorado');
        $sentLow = $this->existing($profile, 'Guia de Nginx antigo');
        $heldLow = $this->existing($profile, 'Guia de Nginx novo', ['delivery_status' => 'held', 'delivery_note' => 'throttled', 'release_at' => now()->addMinutes(5), 'notified_at' => null]);
        $brand = $this->existing($profile, 'Acme Pagamentos vaza dados', [], 'brand');

        $this->artisan('secmon:alerts-review')->expectsOutputToContain('3 alertas: 1 enviaria, 2 seguraria, 0 sem parecer.')->assertSuccessful();

        $this->assertSame([92, null, 'sent'], [$good->refresh()->ai_relevance, $good->suppressed_reason, $good->delivery_status]);
        // Já tinha saído: fica segurado pela IA, mas continua registrado como enviado.
        $this->assertSame([15, 'ai_low_relevance', 'sent'], [$sentLow->refresh()->ai_relevance, $sentLow->suppressed_reason, $sentLow->delivery_status]);
        // Esperava a liberação: não pode mais sair.
        $this->assertSame(['ai_low_relevance', 'skipped', null], [$heldLow->refresh()->suppressed_reason, $heldLow->delivery_status, $heldLow->release_at]);
        $this->assertNull($brand->refresh()->ai_reviewed_at);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'hooks.slack.com'));

        $this->actingAs($profile->user)->get(route('alerts.news', ['filtro' => 'segurados-pela-ia']))
            ->assertSeeInOrder(['Guia de Nginx novo', 'não enviado', 'Guia de Nginx antigo', 'enviado antes do parecer da IA'])
            ->assertDontSee('Nginx RCE explorado');

        // Rodar de novo não refaz nada: só os que ainda não têm parecer.
        $this->artisan('secmon:alerts-review')->expectsOutputToContain('0 alertas')->assertSuccessful();
    }

    public function test_relevant_filter_shows_what_the_ai_approved_and_the_client_vote_wins(): void
    {
        $profile = $this->clientWithNginx();
        $verdict = fn (int $score) => ['ai_relevance' => $score, 'ai_reason' => 'x', 'ai_reviewed_at' => now()] + ($score > 70 ? [] : ['suppressed_reason' => 'ai_low_relevance']);
        $this->existing($profile, 'Aprovado pela IA', $verdict(90));
        $this->existing($profile, 'Aprovado mas o cliente recusou', $verdict(93) + ['feedback' => 'irrelevant', 'feedback_at' => now()]);
        $this->existing($profile, 'Segurado mas o cliente quis', $verdict(20) + ['feedback' => 'relevant', 'feedback_at' => now()]);
        $this->existing($profile, 'Ruído que a IA segurou', $verdict(10));
        $this->existing($profile, 'Cita a empresa', [], 'brand');

        $this->actingAs($profile->user)->get(route('alerts.news', ['filtro' => 'relevantes']))
            ->assertOk()
            ->assertSee('Aprovado pela IA')->assertSee('Segurado mas o cliente quis')->assertSee('Cita a empresa')
            ->assertDontSee('Aprovado mas o cliente recusou')->assertDontSee('Ruído que a IA segurou')
            // Relevantes ao lado de Não relevantes, depois de Pendentes; o número é o da lista.
            ->assertSeeInOrder(['Só no resumo', 'Pendentes', '2', 'Relevantes', '3', 'Não relevantes', 'Segurados pela IA', '1'], false);

        // Pendentes: o que a IA deixou passar e o cliente ainda não avaliou.
        $this->get(route('alerts.news', ['filtro' => 'pendentes']))
            ->assertOk()
            ->assertSee('Aprovado pela IA')->assertSee('Cita a empresa')
            ->assertDontSee('Segurado mas o cliente quis')->assertDontSee('Aprovado mas o cliente recusou')->assertDontSee('Ruído que a IA segurou');
    }

    public function test_pending_leaves_out_what_the_ai_is_still_reviewing(): void
    {
        $profile = $this->clientWithNginx();
        $this->existing($profile, 'Ainda em análise', ['delivery_status' => 'pending', 'delivery_note' => 'ai_review', 'notified_at' => null]);

        $this->actingAs($profile->user)->get(route('alerts.news', ['filtro' => 'pendentes']))
            ->assertOk()->assertDontSee('Ainda em análise')->assertSee('Nenhum alerta pendente');
    }

    public function test_command_dry_run_writes_nothing(): void
    {
        $this->hermes(10, 'Irrelevante.');
        $match = $this->existing($this->clientWithNginx(), 'Guia de Nginx');

        $this->artisan('secmon:alerts-review', ['--dry-run' => true])->expectsOutputToContain('(nada gravado)')->assertSuccessful();

        $this->assertSame([null, null], [$match->refresh()->ai_reviewed_at, $match->suppressed_reason]);
    }

    public function test_parse_tolerates_fences_and_clamps_the_score(): void
    {
        $this->assertSame(['relevance' => 100, 'reason' => 'ok demais'], AlertReviewer::parse("```json\n{\"relevance\": 140, \"reason\": \" ok \n demais \"}\n```"));
        $this->assertSame(55, AlertReviewer::parse('Aqui: {"relevance": "55.4", "reason": "x"}')['relevance']);

        $this->expectException(RuntimeException::class);
        AlertReviewer::parse('não sei');
    }
}
