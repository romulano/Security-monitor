<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\GithubMonitor;
use App\Models\LookalikeWatch;
use App\Models\Sector;
use App\Models\User;
use App\Services\Company\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * E2f: apagar setor (§4.13, DESENHO-5.2 §6.1). Numa transação, o que era do setor fica sem setor (a empresa toda vê) e as
 * contas saem; o diálogo mostra a contagem, que o servidor confere de novo; reautenticação; auditoria; só quem administra.
 */
class SectorDeletionTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private User $bruno;

    private User $caio;

    private AlertProfile $profile;

    private LookalikeWatch $watch;

    private GithubMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ana->forceFill(['password' => 'senha-da-admin-1'])->save();
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
        $this->caio = $this->companyAccount($this->acme);
        $this->joinSector($this->caio, $this->ti);
        $this->profile = $this->profileOf($this->bruno, $this->acme, $this->ti);
        $this->watch = $this->watchOf($this->bruno, $this->acme, $this->ti);
        $this->monitor = $this->monitorOf($this->bruno, $this->acme, $this->ti);
    }

    private function deleteSector(User $who, string $value, array $extra = ['reauth_password' => 'senha-da-admin-1'])
    {
        return $this->actingAs($who)->from(route('company.sectors'))->delete(route('company.sectors.delete'), ['setor' => $value] + $extra);
    }

    public function test_the_admin_deletes_the_sector_and_its_things_go_to_the_whole_company(): void
    {
        $rh = $this->companySector($this->acme, 'RH');
        $dani = $this->companyAccount($this->acme);
        $this->joinSector($dani, $rh);
        $value = $this->ti->id.'-1-1-1-2';
        $summary = '1 alerta de Notícias, 1 Radar e 1 monitoramento do GitHub passam a ser vistos pela empresa toda; 2 contas saem do setor.';

        // O diálogo mostra a contagem, e a opção a leva de volta para o servidor conferir.
        $this->actingAs($this->ana)->get(route('company.sectors'))->assertOk()
            ->assertSee('value="'.$value.'"', false)
            ->assertSee(e("Apagar o setor TI? {$summary} Não dá para desfazer."), false)
            ->assertSee('Os canais de aviso do setor (Slack, Discord, Telegram e webhook) viram canais gerais')
            // Cada cartão tem o seu "Apagar", com ids próprios (Designer, rodada 10).
            ->assertSee('id="reauth-password-apagar-'.$this->ti->id.'"', false)
            ->assertSee('id="reauth-password-apagar-'.$rh->id.'"', false)
            ->assertSee('Apagar TI');
        $this->assertFalse(app(Visibility::class)->sees($dani, $this->watch));

        $this->deleteSector($this->ana, $value)->assertRedirect(route('company.sectors'))->assertSessionHas('status', "Setor apagado. {$summary}");

        $this->assertNull(Sector::query()->find($this->ti->id));
        $this->assertSame(0, DB::table('sector_user')->where('sector_id', $this->ti->id)->count());
        foreach ([$this->profile, $this->watch, $this->monitor] as $item) {
            $this->assertSame([$this->acme->id, null], [$item->fresh()->company_id, $item->fresh()->sector_id]);
        }
        // Sem setor, a empresa toda vê; configurar fica só com quem administra (P1).
        $this->assertTrue(app(Visibility::class)->sees($dani, $this->watch->fresh()));
        $this->assertFalse(app(Visibility::class)->manages($this->bruno, $this->watch->fresh()));
        $this->assertSame([$rh->id], Sector::query()->where('company_id', $this->acme->id)->pluck('id')->all());

        $entry = DB::table('company_audit')->where('action', 'sector.deleted')->sole();
        $this->assertEquals(['setor' => 'TI', 'setor_id' => $this->ti->id, 'alertas' => 1, 'radares' => 1, 'github' => 1, 'contas' => 2], json_decode($entry->data, true));
        $this->actingAs($this->ana)->get(route('company.activity'))->assertSee("Setor apagado: TI. {$summary}");
    }

    public function test_a_count_that_changed_since_the_dialog_is_refused(): void
    {
        // Alguém criou outro Radar no setor depois que a página abriu: a contagem do diálogo já não vale.
        $this->watchOf($this->bruno, $this->acme, $this->ti);

        $this->deleteSector($this->ana, $this->ti->id.'-1-1-1-2')
            ->assertSessionHas('error', 'O setor TI mudou desde que você abriu a página: 1 alerta de Notícias, 2 Radares e 1 monitoramento do GitHub passam a ser vistos pela empresa toda; 2 contas saem do setor. Confira e apague de novo.');
        $this->assertNotNull(Sector::query()->find($this->ti->id));
        $this->assertSame($this->ti->id, $this->watch->fresh()->sector_id);
        $this->assertSame(0, DB::table('company_audit')->where('action', 'sector.deleted')->count());
    }

    public function test_only_the_admin_with_reauthentication_deletes_a_sector_of_her_company(): void
    {
        $value = $this->ti->id.'-1-1-1-2';
        [$beta, $paulo] = $this->activeCompany('Beta', 'paulo@beta.com');
        $paulo->forceFill(['password' => 'senha-da-admin-1'])->save();
        $vendas = $this->companySector($beta, 'Vendas');

        // Sem confirmar que é ela, nada sai.
        $this->deleteSector($this->ana, $value, [])->assertSessionHasErrors('reauth_password', null, 'reauth');
        $this->deleteSector($this->ana, $value, ['reauth_password' => 'errada-123', '_apagar' => $this->ti->id])->assertSessionHasErrors('reauth_password', null, 'reauth');
        // O erro volta só no cartão que foi enviado, aberto.
        $this->actingAs($this->ana)->get(route('company.sectors'))
            ->assertSee('id="reauth-password-error-apagar-'.$this->ti->id.'"', false);

        // Responsável, membro e conta pessoal: 404 antes de tudo; a admin da Beta com o setor da Acme: recusa.
        foreach ([$this->bruno, $this->caio, User::factory()->create()] as $who) {
            $this->deleteSector($who, $value)->assertNotFound();
        }
        $this->deleteSector($paulo, $value)->assertSessionHas('error', 'Escolha um setor da empresa.');
        $this->deleteSector($this->ana, $vendas->id.'-0-0-0-0')->assertSessionHas('error', 'Escolha um setor da empresa.');
        $this->deleteSector($this->ana, 'TI')->assertSessionHasErrors('setor');

        $this->assertNotNull(Sector::query()->find($this->ti->id));
        $this->assertNotNull(Sector::query()->find($vendas->id));
        $this->assertSame(0, DB::table('company_audit')->where('action', 'sector.deleted')->count());

        config(['secmon.enterprise.enabled' => false]);
        $this->deleteSector($this->ana, $value)->assertNotFound();
    }

    // A rede de segurança da E2b0: um setor apagado por fora do serviço deixa os itens sem setor, sem apagá-los.
    public function test_the_foreign_keys_leave_the_items_without_sector_when_the_row_goes(): void
    {
        DB::table('sectors')->where('id', $this->ti->id)->delete();

        foreach ([$this->profile, $this->watch, $this->monitor] as $item) {
            $this->assertSame([$this->acme->id, null], [$item->fresh()?->company_id, $item->fresh()?->sector_id]);
        }
        $this->assertSame(0, DB::table('sector_user')->where('sector_id', $this->ti->id)->count());
    }
}
