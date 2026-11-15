<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Jobs\SendEmail;
use App\Mail\CompanyLimitNotice;
use App\Models\Company;
use App\Models\User;
use App\Services\Company\CompanyLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** E5d, rodada 3 da Designer: os textos dos limites (quem administra × quem responde pelo setor), o "{n} de {max}" e o e-mail. */
class CompanyLimitTextsTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $ti = $this->companySector($this->acme, 'TI');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $ti, manager: true);
    }

    /** @return list<CompanyLimitNotice> */
    private function mails(): array
    {
        return Queue::pushed(SendEmail::class)->map(fn ($job) => $job->mail)->filter(fn ($mail) => $mail instanceof CompanyLimitNotice)->values()->all();
    }

    private function text(CompanyLimitNotice $mail): string
    {
        return view($mail->content()->text, $mail->buildViewData() + $mail->content()->with)->render();
    }

    public function test_the_alert_limit_reads_differently_for_the_admin_and_the_sector_manager_and_the_count_shows_before(): void
    {
        $this->acme->forceFill(['limit_alert_profiles' => 5])->save();
        foreach (range(1, 4) as $i) {
            $this->profileOf($this->ana, $this->acme);
        }

        // Antes do limite, para quem cria: quantos a empresa já usa, em âmbar a partir de 80%.
        $this->actingAs($this->ana)->get(route('alerts.news'))->assertOk()
            ->assertSee('4 de 5 alertas da empresa')->assertSee('text-amber-300', false)->assertDontSee('data-alerts-full', false);
        $this->actingAs($this->companyAccount($this->acme))->get(route('alerts.news'))->assertDontSee('alertas da empresa');

        $this->profileOf($this->ana, $this->acme);
        $limits = app(CompanyLimits::class);
        $this->refreshVisibility();
        $this->actingAs($this->ana)->get(route('alerts.news'))->assertSee('A empresa chegou ao limite de 5 alertas de Notícias. Para criar outro, exclua um que não use mais. Precisa de mais? Escreva para contato@example.com.')
            ->assertDontSee('5 de 5 alertas da empresa');
        $this->refreshVisibility();
        $this->actingAs($this->bruno)->get(route('alerts.news'))
            ->assertSee('A empresa chegou ao limite de 5 alertas de Notícias (somando todos os setores). Para criar outro, exclua um do seu setor que não use mais ou fale com quem administra a empresa.');

        try {
            $limits->assertAlertRoom($this->acme, $this->bruno);
            $this->fail('passou do limite');
        } catch (ActionRefused $e) {
            $this->assertStringContainsString('fale com quem administra a empresa', $e->getMessage());
        }
    }

    public function test_the_limit_email_says_what_to_do_names_the_screen_and_who_it_is_for(): void
    {
        $this->acme->forceFill(['limit_accounts' => 3])->save();
        // Duas contas e um convite pendente: 3 de 3.
        DB::table('company_invites')->insert(['company_id' => $this->acme->id, 'email' => 'x@acme.com', 'role' => 'member', 'token_hash' => hash('sha256', Str::random(9)),
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        app(CompanyLimits::class)->notify($this->acme, 'accounts');

        $mail = collect($this->mails())->sole();
        $this->assertSame('Security Monitor: Acme chegou ao limite de contas', $mail->envelope()->subject);
        $text = $this->text($mail);
        $this->assertStringContainsString('chegou ao limite de 3 contas (os convites pendentes contam). Para convidar outra pessoa, remova uma conta ou cancele um convite pendente.', $text);
        $this->assertStringContainsString('Ver as contas da empresa: ', $text);
        $this->assertStringContainsString('Se precisar de mais, fale com o Security Monitor pelo contato@example.com.', $text);
        $this->assertStringContainsString('Você recebe este aviso porque administra a conta da empresa no Security Monitor.', $text);

        // Os canais do setor: a admin e o responsável recebem, cada um com o seu texto.
        $this->acme->forceFill(['limit_sector_channels' => 5])->save();
        $sector = $this->bruno->sectors()->sole();
        foreach (range(1, 4) as $i) {
            DB::table('notification_channels')->insert(['user_id' => $this->bruno->id, 'company_id' => $this->acme->id, 'sector_id' => $sector->id, 'type' => 'webhook', 'method' => 'json',
                'webhook_url' => 'https://hooks.acme.com.br/'.$i, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(CompanyLimits::class)->notify($this->acme, 'sector_channels', $sector->id);

        $texts = collect($this->mails())->filter(fn ($m) => $m->limit === 'sector_channels')->mapWithKeys(fn ($m) => [$m->name => $this->text($m)]);
        $this->assertCount(2, $texts);
        $manager = $texts[$this->bruno->name];
        $this->assertStringContainsString('já usa 4 de 5 canais do setor TI. Quando chegar a 5, não dá para cadastrar mais até remover algum.', $manager);
        $this->assertStringContainsString('Ver os Notificadores: ', $manager);
        $this->assertStringContainsString('Se precisar de mais, fale com quem administra a empresa.', $manager);
        $this->assertStringContainsString('Você recebe este aviso porque responde pelo setor TI na conta da empresa.', $manager);
        $this->assertStringContainsString('porque administra a conta da empresa', $texts['Admin Acme']);
    }

    public function test_admin_companies_counts_pending_invites_in_the_accounts(): void
    {
        DB::table('company_invites')->insert(['company_id' => $this->acme->id, 'email' => 'x@acme.com', 'role' => 'member', 'token_hash' => hash('sha256', Str::random(9)),
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $secmon = User::factory()->create(['is_admin' => true]);

        $this->actingAs($secmon)->get(route('admin.companies.index'))->assertOk()
            ->assertSee('1 empresa.')
            ->assertSeeInOrder(['Contas', '3', '/ 10', '1 convite pendente conta aqui', 'Domínios']);
    }
}
