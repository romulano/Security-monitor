<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Http\Requests\Account\SendReauthCodeRequest;
use App\Models\NotificationChannel;
use App\Models\PentestRun;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Pentest\Engine\PentestWatch;
use App\Services\Pentest\PentestBanner;
use App\Services\Pentest\PentestConsents;
use App\Services\Pentest\PentestEnrollment;
use App\Services\Pentest\PentestWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Feature\Lookalike\LookalikeTestHelpers;
use Tests\Feature\Pentest\PentestRunHelpers;
use Tests\Feature\Pentest\PentestTestHelpers;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * Red Team da E3t (P4 do 5.2). `rt_*`: o ataque que passava no 55b1ac9 e o patch fecha; `evidencia_*`: o que resistiu.
 *
 * Achado V1: a conta que entra numa empresa com um teste agendado, esperando a aprovação, pausado pelo sistema ou
 * rodando perde a tela (404, inclusive o Parar e a faixa), mas o motor segue: o vigia começa a agendada, retoma a
 * pausada e deixa a que roda seguir; o admin aprova a que esperava. Tráfego novo ao site de uma conta de empresa, sem
 * o dono ver nem conseguir parar. O portão passa a parar (A5d), como faz com a liberação retirada (A5c).
 */
class RedTeamE3tTest extends TestCase
{
    use LookalikeTestHelpers, PentestRunHelpers, PentestTestHelpers, RefreshDatabase, VisibilityFixtures;

    private User $user;

    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->app->instance(DnsResolver::class, $this->dns = new FakeDnsResolver);
        $this->switchOn();
        $this->user = $this->grant($this->client());
    }

    /** A conta entra numa empresa (o aceite do convite grava a linha), com tudo o que já tinha. */
    private function joinCompany(User $user): void
    {
        [$company] = $this->activeCompany('Acme', 'admin@acme.com.br');
        $company->members()->forceCreate(['user_id' => $user->id, 'role' => CompanyRole::Member, 'joined_at' => now()]);
        $user->unsetRelation('companyMembership');
    }

    public function test_rt_v1_a_scheduled_run_never_starts_after_the_account_joins_a_company(): void
    {
        $run = $this->scheduled();
        $this->joinCompany($this->user);
        $this->engineComplete();
        $this->inWindow();

        // A tela já some (a E3t): nem o Parar nem a faixa.
        $this->actingAs($this->user)->post(route('alerts.tests.runs.stop', ['execucao' => $run->uuid]))->assertNotFound();
        $this->assertNull(app(PentestBanner::class)->for($this->user->refresh(), now()));

        // O vigia, no minuto em que a janela abre: no 55b1ac9, começava (fase 1) para a conta de empresa.
        app(PentestWatch::class)->tick(now());

        $this->assertSame(['stopped', 'A5d'], [$run->refresh()->status, $run->stop_reason]);
        $this->assertSame(0, PentestRun::query()->where('status', 'running')->count());
    }

    public function test_rt_v1_a_running_run_stops_at_the_next_check(): void
    {
        $run = $this->running();
        $this->joinCompany($this->user);

        // No 55b1ac9, seguia rodando e mandando as ações da fase; o dono só via 404.
        $this->machine()->continue($run, now());

        $this->assertSame(['stopping', 'A5d'], [$run->refresh()->status, $run->stop_reason]);
    }

    public function test_rt_v1_the_secmon_admin_cannot_approve_a_run_of_a_company_account(): void
    {
        $run = $this->runs()->start($this->authorized(), $this->user, now());
        $this->assertSame('awaiting_approval', $run->status);
        $this->joinCompany($this->user);

        // No 55b1ac9, a aprovação agendava a execução (e o vigia começava depois).
        $decision = $this->machine()->approve($run, User::query()->findOrFail($this->analyst()), now());

        $this->assertFalse($decision->allows());
        $this->assertNotSame('scheduled', $run->refresh()->status);
    }

    public function test_evidencia_the_404_comes_before_the_throttle_and_the_reauth(): void
    {
        $domain = $this->authorized();
        $this->joinCompany($this->user);
        $this->actingAs($this->user);

        // Acima do limite de cada rota: sempre 404, nunca 429 nem o pedido de senha (302 para a reautenticação).
        foreach (range(1, 12) as $i) {
            $this->post(route('alerts.tests.runs.store', ['dominio' => $domain->host]))->assertNotFound();
            $this->post(route('alerts.tests.domains.accept', ['dominio' => $domain->host]))->assertNotFound();
            $this->post(route('alerts.tests.access.request'))->assertNotFound();
        }

        // E o 404 não gasta o limite: de volta à conta pessoal, o pedido seguinte não é 429.
        $this->user->companyMembership()->delete();
        $this->assertNotSame(429, $this->actingAs($this->user->refresh())->post(route('alerts.tests.access.request'))->status());
    }

    public function test_evidencia_the_side_doors_stay_closed(): void
    {
        // O rascunho no passo 7 (só falta o Autorizar): o código de reautenticação da conta pessoal leva o domínio (Q6).
        $draft = app(PentestEnrollment::class)->add($this->user, $this->provenId($this->watch($this->verifiedDomain($this->user, 'rascunho.com.br', consent: 'radar-v1'))->domain), null, null);
        $consents = app(PentestConsents::class);
        $preview = $consents->preview($draft, now());
        $consents->declare($draft, array_keys($preview['declarations']), $preview['rendered_sha256'], now());
        $draft->refresh()->forceFill(['step_reached' => PentestWizard::LAST_STEP])->save();
        $this->assertSame(PentestWizard::LAST_STEP, PentestWizard::reachable($draft->refresh()));
        $context = function () {
            $request = SendReauthCodeRequest::create('/conta/reautenticar/codigo', 'POST', ['dominio' => 'rascunho.com.br']);
            $request->setUserResolver(fn () => $this->user->refresh());

            return $request->context();
        };
        $this->assertSame('rascunho.com.br', $context());

        $domain = $this->authorized();
        $this->joinCompany($this->user);

        // Na conta de empresa, o contexto é nulo: a mensagem oficial nunca nomeia o domínio da stack (L1).
        $this->assertNull($context());

        // A Central não oferece o tipo "Testes de segurança".
        $this->assertArrayNotHasKey(NotificationChannel::TESTS, NotificationChannel::typesFor($this->user));

        // A faixa e a consulta periódica: 404 também com a execução de antes.
        $run = $this->scheduled($domain);
        $this->actingAs($this->user)->get(route('alerts.tests.banner'))->assertNotFound();
        $this->get(route('alerts.tests.runs.status', ['execucao' => $run->uuid]))->assertNotFound();
    }
}
