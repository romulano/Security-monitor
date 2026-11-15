<?php

namespace Tests\Feature\Alerts;

use App\Jobs\MatchCaseToProfiles;
use App\Models\AlertMatch;
use App\Models\GithubFinding;
use App\Models\LookalikeAlert;
use App\Models\LookalikeDomain;
use App\Models\User;
use App\Services\Alerts\ProfileIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Github\GithubTestHelpers;
use Tests\Feature\Lookalike\LookalikeTestHelpers;
use Tests\TestCase;

/** A aba Alertas (tudo o que as três fontes acharam) e a aba Integração (cada fonte com a sua entrevista). */
class AlertsHubTest extends TestCase
{
    use GithubTestHelpers, LookalikeTestHelpers, RefreshDatabase {
        LookalikeTestHelpers::client insteadof GithubTestHelpers;
        LookalikeTestHelpers::adminUser insteadof GithubTestHelpers;
        LookalikeTestHelpers::profile insteadof GithubTestHelpers;
        LookalikeTestHelpers::newsCase insteadof GithubTestHelpers;
        LookalikeTestHelpers::article insteadof GithubTestHelpers;
        LookalikeTestHelpers::slackChannel insteadof GithubTestHelpers;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
    }

    /** Uma notícia, um aviso do Radar segurado pela IA e um achado do GitHub enviado, do mesmo cliente. */
    private function everything(): User
    {
        $user = $this->client();
        $this->profile($user, ['language' => 'pt'], ['nginx']);
        $case = $this->newsCase('Falha no Nginx permite RCE', 'critical', ['affected_products' => ['nginx 1.24']]);
        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));
        AlertMatch::query()->update(['matched_at' => now()->subHours(3)]);

        $watch = $this->watch($this->verifiedDomain($user));
        $lookalike = $this->lookalike($watch, 'acmepay.shop', ['risk' => 'high', 'state' => 'content']);
        LookalikeAlert::query()->create(['lookalike_id' => $lookalike->id, 'kind' => 'new', 'risk' => 'high', 'status' => 'held', 'ai_score' => 38, 'created_at' => now()->subHours(2)]);

        $this->finding($this->monitor($user), ['status' => 'sent', 'triage_level' => 'critical', 'ai_score' => 93, 'notified_at' => now()->subHour()]);

        return $user;
    }

    public function test_hub_lists_what_the_three_sources_found_newest_first(): void
    {
        $user = $this->everything();

        $this->actingAs($user)->get(route('alerts.index'))
            ->assertOk()
            ->assertSee('Linha do tempo')
            ->assertSeeInOrder(['dev-fulano/teste-api/config/.env', 'acmepay.shop', 'Falha no Nginx permite RCE'])
            ->assertSee('Segurado pela IA')
            ->assertSee('Apareceu já em Alto · parecido com acmepay.com.br')
            ->assertSee('Nome de chave sua em código público')
            ->assertSee(route('alerts.lookalikes.show', $user->lookalikeWatches()->first()->lookalikes()->first()), false);

        $this->actingAs($user)->get(route('alerts.index', ['filtro' => 'radar']))
            ->assertSee('acmepay.shop')->assertDontSee('Falha no Nginx permite RCE')->assertDontSee('teste-api/config/.env');
        $this->actingAs($user)->get(route('alerts.index', ['filtro' => 'noticias']))
            ->assertSee('Falha no Nginx permite RCE')->assertDontSee('acmepay.shop');
        $this->actingAs($user)->get(route('alerts.index', ['filtro' => 'segurados-pela-ia']))
            ->assertSee('acmepay.shop')->assertDontSee('Falha no Nginx permite RCE');

        // Filtro desconhecido vale "Todos"; o de outro cliente não aparece.
        $this->actingAs($user)->get(route('alerts.index', ['filtro' => 'xyz']))->assertOk()->assertSee('Falha no Nginx permite RCE');
        $this->actingAs($this->client())->get(route('alerts.index'))->assertOk()->assertDontSee('acmepay.shop');
    }

    public function test_hub_has_the_situation_filters_of_every_source(): void
    {
        $user = $this->everything();
        // A notícia saiu na hora e foi marcada como não relevante; o Radar foi segurado pela IA; o GitHub saiu e ninguém avaliou.
        AlertMatch::query()->update(['delivery_status' => 'sent', 'delivery_note' => null, 'feedback' => 'irrelevant']);

        $page = fn (array $query) => $this->actingAs($user)->get(route('alerts.index', $query))->assertOk();

        $page(['filtro' => 'pendentes'])->assertSee('teste-api/config/.env')->assertDontSee('Falha no Nginx permite RCE')->assertDontSee('acmepay.shop');
        $page(['filtro' => 'nao-relevantes'])->assertSee('Falha no Nginx permite RCE')->assertDontSee('acmepay.shop')->assertDontSee('teste-api/config/.env');
        $page(['filtro' => 'na-hora'])->assertSee('Falha no Nginx permite RCE')->assertSee('teste-api/config/.env')->assertDontSee('acmepay.shop');
        $page(['filtro' => 'segurados-pela-ia'])->assertSee('acmepay.shop')->assertDontSee('Falha no Nginx permite RCE')->assertDontSee('teste-api/config/.env');

        // Fonte e situação juntas, e os números de cada filtro na fonte escolhida.
        $page(['fonte' => 'github', 'filtro' => 'na-hora'])
            ->assertSee('teste-api/config/.env')->assertDontSee('Falha no Nginx permite RCE')
            ->assertSee('href="'.e(route('alerts.index', ['fonte' => 'github', 'filtro' => 'pendentes'])).'"', false);
        $page(['fonte' => 'radar', 'filtro' => 'na-hora'])->assertSee('Nada saiu na hora desta fonte.');
        // Sem fonte escolhida, "Todas" fica marcada e os filtros de situação não levam fonte nenhuma.
        $all = $page([])
            ->assertSee('href="'.route('alerts.index', ['filtro' => 'pendentes']).'"', false)
            ->assertDontSee('fonte=github&amp;filtro=', false);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('alerts.index'), '/').'"\s+aria-current="page"/', $all->getContent());

        // "É golpe" no Radar conta como relevante, mesmo segurado pela IA; "não relevante" na notícia tira ela dos relevantes.
        LookalikeDomain::query()->update(['verdict' => 'threat']);
        $page(['filtro' => 'relevantes'])->assertSee('acmepay.shop')->assertSee('teste-api/config/.env')->assertDontSee('Falha no Nginx permite RCE');
    }

    public function test_hub_rows_carry_the_reason_the_vote_and_the_resend_of_each_source(): void
    {
        $user = $this->everything();
        $this->slackChannel($user);
        AlertMatch::query()->update(['delivery_status' => 'sent', 'delivery_note' => null]);
        $match = AlertMatch::query()->first();
        $finding = GithubFinding::query()->first();
        $alert = LookalikeAlert::query()->first();

        // Ids dos painéis com a fonte na frente: a notícia, o aviso e o achado podem ter o mesmo número.
        $this->actingAs($user)->get(route('alerts.index'))
            ->assertOk()
            ->assertSee('popovertarget="motivo-n'.$match->id.'"', false)
            ->assertSee('popovertarget="voto-n'.$match->id.'" data-vote="relevant"', false)
            ->assertSee('action="'.route('alerts.feedback', $match).'"', false)
            ->assertSee('action="'.route('alerts.resend', $match).'"', false)
            ->assertSee('popovertarget="motivo-g'.$finding->id.'"', false)
            ->assertSee('action="'.route('alerts.github.feedback', $finding).'"', false)
            ->assertSee('action="'.route('alerts.github.resend', $finding).'"', false)
            ->assertSee('popovertarget="motivo-r'.$alert->id.'"', false)
            ->assertSee('popovertarget="voto-r'.$alert->id.'" data-vote="golpe"', false)
            ->assertSee('action="'.route('alerts.lookalikes.verdict', $alert->lookalike).'"', false);

        // Avaliar pela aba Alertas volta para ela, no mesmo filtro.
        $back = route('alerts.index', ['filtro' => 'segurados-pela-ia']);
        $this->actingAs($user)->from($back)->post(route('alerts.lookalikes.verdict', $alert->lookalike), ['acao' => 'golpe', 'note' => 'Copia o nosso login.'])
            ->assertRedirect($back);
        $this->assertSame('threat', $alert->lookalike->refresh()->verdict);
        $this->actingAs($user)->get($back)->assertSee('marcado como “É golpe”', false);
    }

    public function test_hub_without_any_source_points_to_the_integration(): void
    {
        $this->actingAs($this->client())->get(route('alerts.index'))
            ->assertOk()
            ->assertSee('Escolha o que o Security Monitor vai vigiar')
            ->assertSee(route('alerts.integrations'), false)
            ->assertDontSee('Linha do tempo');
    }

    public function test_hub_warns_about_a_broken_app(): void
    {
        $user = $this->client();
        $this->profile($user);
        $this->slackChannel($user, ['status' => 'broken', 'last_error' => 'channel_not_found']);

        $this->actingAs($user)->get(route('alerts.index'))
            ->assertOk()
            ->assertSee('O Slack recusou o último envio.')
            ->assertSee('reconectar');
    }

    public function test_integration_page_offers_each_source_with_its_own_interview(): void
    {
        $user = $this->client();

        $this->actingAs($user)->get(route('alerts.integrations'))
            ->assertOk()
            ->assertSeeInOrder(['Notícias', 'não integrado', 'Radar de domínios', 'não integrado', 'GitHub', 'não integrado'])
            ->assertSee(route('alerts.interview', ['passo' => 1]), false)
            ->assertSee(route('alerts.lookalikes.interview', ['passo' => 1]), false)
            ->assertSee(route('alerts.github.interview', ['passo' => 1]), false)
            ->assertSee('nenhum app conectado');

        // Notícias com o domínio comprovado: o card do Radar oferece reaproveitá-lo. Radar em rascunho: continuar.
        $profile = $this->profile($user);
        $profile->domains()->create(['domain' => 'acmepay.com.br', 'verify_token' => 'x', 'verified_at' => now()]);
        $this->actingAs($user)->get(route('alerts.integrations'))
            ->assertSeeInOrder(['Notícias', 'ativo', 'alerta', '1', 'nos últimos 30 dias', 'pendentes'])
            ->assertSee('Reaproveita')
            ->assertSee('Abrir Notícias');

        $watch = $this->watch($this->verifiedDomain($user, 'acmeconta.com.br'), ['status' => 'draft', 'completed_at' => null, 'step_reached' => 2]);
        $this->actingAs($user)->get(route('alerts.integrations'))
            ->assertSee('rascunho · passo 2')
            ->assertSee(route('alerts.lookalikes.interview', ['passo' => 2, 'radar' => $watch->id]), false);

        $this->monitor($user, ['status' => 'paused']);
        $this->actingAs($user)->get(route('alerts.integrations'))->assertSee('Abrir o GitHub');
    }
}
