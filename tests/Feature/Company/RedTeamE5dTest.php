<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Jobs\SendEmail;
use App\Mail\CompanyLimitNotice;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\LookalikeWatch;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\InterviewService;
use App\Services\Company\CompanyLimits;
use App\Services\Lookalike\RadarInterview;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** Red Team B do 5.4 na E5d (P7): os limites da empresa no lugar do "5 por criador". */
class RedTeamE5dTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private Company $acme;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(DnsResolver::class, new FakeDnsResolver);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'domain' => 'acme.com.br', 'source' => 'company',
            'proof_token' => $this->acme->domain_token, 'verified_at' => now()]);
    }

    private function radar(User $by, string $domain): LookalikeWatch
    {
        return app(RadarInterview::class)->saveDomain($by, null, null, $domain, null);
    }

    /**
     * X1: sem o "5 por criador", quem limita o Radar da empresa seria o domínio (um por domínio, até os 15). Mas o nome
     * de baixo de um domínio comprovado não gasta domínio (o claim usa a linha de cima, E3b) e cada nome é "outro"
     * domínio para o "Já existe um Radar": a.acme.com.br, b.acme.com.br... dão Radares sem fim (cada um, uma varredura).
     */
    public function test_rt_x1_radars_on_names_under_a_proven_domain_have_no_ceiling(): void
    {
        $this->acme->forceFill(['limit_domains' => 2])->save();

        $refused = null;

        foreach (['a', 'b', 'c'] as $name) {
            try {
                $this->radar($this->ana, "{$name}.acme.com.br");
            } catch (ActionRefused $e) {
                $refused = $e->getMessage();
            }
        }

        $this->assertSame(1, OwnedDomain::query()->where('company_id', $this->acme->id)->count(), 'nenhum nome de baixo gastou domínio');
        $this->assertLessThanOrEqual(2, LookalikeWatch::query()->where('company_id', $this->acme->id)->count(), 'Radares da empresa acima do limite de domínios');
        $this->assertNotNull($refused);
        $this->assertStringContainsString('2 Radares', $refused);
    }

    /**
     * X2: o "Já existe um Radar para X na empresa" é conferido antes da trava da empresa: dois pedidos juntos (duplo
     * clique, duas abas, a admin e um responsável) passam os dois e o mesmo domínio ganha dois Radares. Na simulação, o
     * segundo pedido entra logo depois de o primeiro travar a empresa (em produção ele espera a trava e cria do mesmo jeito).
     */
    public function test_rt_x2_two_radars_for_the_same_domain_at_the_same_time(): void
    {
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired) {
            if ($fired || ! str_contains($query->sql, 'from "companies"') || ! str_contains($query->sql, 'for update')) {
                return;
            }

            $fired = true;
            $this->radar($this->ana, 'loja.acme.com.br');
        });

        try {
            $this->radar($this->ana, 'loja.acme.com.br');
            $refused = false;
        } catch (ValidationException) {
            $refused = true;
        }

        $this->assertTrue($fired);
        $this->assertLessThanOrEqual(1, LookalikeWatch::query()->whereHas('domain', fn ($q) => $q->where('domain', 'loja.acme.com.br'))->count(), 'dois Radares para o mesmo domínio');
        $this->assertTrue($refused, 'o segundo leva "Já existe um Radar"');
    }

    /** Evidência: o rascunho conta no limite de alertas (o duplo clique não passa) e a mensagem não diz o que há em outro setor. */
    public function test_evidencia_drafts_count_and_the_message_says_only_the_total(): void
    {
        $this->acme->forceFill(['limit_alert_profiles' => 2])->save();
        $ti = $this->companySector($this->acme, 'TI');
        $bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($bruno, $ti, true);
        app(InterviewService::class)->create($this->ana);
        $this->assertSame('draft', AlertProfile::query()->value('status'));
        app(InterviewService::class)->create($this->ana);

        try {
            app(InterviewService::class)->create($bruno, null, $ti->id);
            $this->fail('passou do limite com rascunhos');
        } catch (ActionRefused $e) {
            $this->assertSame('A empresa chegou ao limite de 2 alertas de Notícias (somando todos os setores). Para criar outro, exclua um do seu setor que não use mais ou fale com quem administra a empresa.', $e->getMessage());
        }

        $notices = Queue::pushed(SendEmail::class)->map(fn ($job) => $job->mail)->filter(fn ($m) => $m instanceof CompanyLimitNotice);
        $this->assertSame(['Admin Acme'], $notices->pluck('name')->unique()->values()->all(), 'o aviso de alertas é só da admin');
    }

    /** Evidência: a empresa suspensa não gasta o marco do aviso (o próximo cadastro depois de reativar avisa). */
    public function test_evidencia_a_suspended_company_gets_no_notice(): void
    {
        $this->acme->forceFill(['limit_alert_profiles' => 1, 'suspended_at' => now()])->save();
        $this->profileOf($this->ana, $this->acme);
        app(CompanyLimits::class)->notify($this->acme, 'alert_profiles');

        $this->assertSame(0, Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyLimitNotice)->count());
        $this->assertSame(0, DB::table('company_limit_notices')->count());
    }
}
