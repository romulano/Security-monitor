<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Jobs\SendEmail;
use App\Mail\CompanyInviteLink;
use App\Models\Company;
use App\Models\CompanyInvite;
use App\Models\Sector;
use App\Models\User;
use App\Services\Company\Sectors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** Red Team da E2f (apagar setor). Sem achado: as evidências. */
class RedTeamE2fTest extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private Sector $rh;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ana->forceFill(['password' => 'senha-da-admin-1'])->save();
        $this->ti = app(Sectors::class)->create($this->ana, 'TI');
        $this->rh = app(Sectors::class)->create($this->ana, 'RH');
    }

    private function value(Sector $sector): string
    {
        return $sector->id.'-'.implode('-', app(Sectors::class)->counts($sector));
    }

    // Evidência — o convite pendente que escolheu o setor apagado: o aceite segue, só com os setores que ainda existem;
    // a contagem de contas do setor não inclui o convite (ele ainda não é conta).
    public function test_evidencia_convite_pendente_com_o_setor_apagado(): void
    {
        $this->actingAs($this->ana)->post(route('company.invites.store'), ['email' => 'bia@acme.com', 'role' => 'sector_manager', 'sectors' => [$this->ti->id, $this->rh->id]])
            ->assertSessionHas('status');
        $this->actingAs($this->ana)->delete(route('company.sectors.delete'), ['setor' => $this->value($this->ti), 'reauth_password' => 'senha-da-admin-1'])
            ->assertSessionHas('status');
        $this->assertSame(1, DB::table('company_audit')->where('action', 'sector.deleted')->count());

        auth()->logout();
        $link = Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteLink)->last()->mail->url;
        $this->post($link, ['name' => 'Bia Souza', 'password' => 'senha-da-bia-1', 'password_confirmation' => 'senha-da-bia-1'])->assertRedirect();

        $bia = User::query()->where('name', 'Bia Souza')->sole();
        $this->assertSame(['RH'], $bia->managedSectors()->pluck('name')->all());
        $this->assertNotNull(CompanyInvite::query()->sole()->accepted_at);
    }

    // Evidência — quem não administra leva 404 antes do throttle e da reautenticação (nunca 429 nem a tela de senha).
    public function test_evidencia_404_antes_do_throttle_e_do_reauth(): void
    {
        $bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($bruno, $this->ti, manager: true);

        foreach (range(1, 12) as $i) {
            $this->actingAs($bruno)->delete(route('company.sectors.delete'), ['setor' => $this->value($this->ti)])->assertNotFound();
        }

        $this->assertNotNull($this->ti->fresh());
    }
}
