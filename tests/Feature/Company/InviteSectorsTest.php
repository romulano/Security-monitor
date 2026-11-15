<?php

namespace Tests\Feature\Company;

use App\Jobs\SendEmail;
use App\Mail\CompanyInviteLink;
use App\Models\Company;
use App\Models\CompanyInvite;
use App\Models\Sector;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\Sectors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** E2a3: os setores pré-escolhidos no convite (§4.1): escolhidos por quem administra, entram no aceite, na mesma transação. */
class InviteSectorsTest extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        config(['secmon.enterprise.enabled' => true]);
        Queue::fake();
        [$this->acme, $this->ana] = $this->company('Acme', 'ana@acme.com');
    }

    /** @return array{Company, User} uma empresa ativa e a admin dela, que já entrou */
    private function company(string $name, string $email): array
    {
        $company = app(Companies::class)->create($name, 'Admin '.$name, $email);
        app(Companies::class)->enable($company);
        $admin = User::query()->where('email', $email)->sole();
        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->companyMembership->forceFill(['joined_at' => now()])->save();

        return [$company, $admin];
    }

    private function sector(string $name, ?User $admin = null): Sector
    {
        return app(Sectors::class)->create($admin ?? $this->ana, $name);
    }

    private function lastLink(): string
    {
        return Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteLink)->last()->mail->url;
    }

    private function accept(string $name = 'Bia Souza'): User
    {
        auth()->logout();
        $this->post($this->lastLink(), ['name' => $name, 'password' => 'senha-da-bia-1', 'password_confirmation' => 'senha-da-bia-1'])->assertRedirect();

        return User::query()->where('name', $name)->sole();
    }

    public function test_the_invited_account_enters_the_chosen_sectors_on_accepting(): void
    {
        $ti = $this->sector('TI');
        $rh = $this->sector('RH');
        $this->sector('Vendas');

        $this->actingAs($this->ana)->get(route('company.accounts'))->assertSee('Setores (opcional)')->assertSee('name="sectors[]" value="'.$ti->id.'"', false);
        $this->actingAs($this->ana)->post(route('company.invites.store'), ['email' => 'bia@acme.com', 'role' => 'member', 'sectors' => [$ti->id, $rh->id, $ti->id]])
            ->assertSessionHas('status');
        $invite = CompanyInvite::query()->sole();
        $this->assertEqualsCanonicalizing([$ti->id, $rh->id], $invite->sector_ids);
        $this->actingAs($this->ana)->get(route('company.accounts'))->assertSee('Membro ·')->assertSee('RH, TI ·');

        $bia = $this->accept();
        $this->assertEqualsCanonicalizing(['TI', 'RH'], $bia->sectors()->pluck('name')->all());
        $this->assertSame(0, $bia->managedSectors()->count());

        $this->actingAs($this->ana)->get(route('company.activity'))->assertOk()
            ->assertSee('Convite enviado: bia@acme.com como Membro (setores: RH, TI)')
            ->assertSee('Convite aceito: Bia Souza como Membro (setores: RH, TI)');
    }

    public function test_a_sector_manager_answers_for_the_sectors_and_deleted_sectors_are_ignored(): void
    {
        $ti = $this->sector('TI');
        $rh = $this->sector('RH');
        $this->actingAs($this->ana)->post(route('company.invites.store'), ['email' => 'bia@acme.com', 'role' => 'sector_manager', 'sectors' => [$ti->id, $rh->id]]);

        // O RH some entre o convite e o aceite (a E2f apaga setores): o aceite segue, só com o TI.
        DB::table('sectors')->where('id', $rh->id)->delete();
        $bia = $this->accept();

        $this->assertSame(['TI'], $bia->managedSectors()->pluck('name')->all());
        $this->assertSame(1, DB::table('sector_user')->where('user_id', $bia->id)->count());
        $this->actingAs($this->ana)->get(route('company.activity'))->assertSee('Convite aceito: Bia Souza como Responsável do setor (setores: TI)');
    }

    public function test_on_accepting_only_sectors_still_of_the_company_count(): void
    {
        $ti = $this->sector('TI');
        [, $paulo] = $this->company('Beta', 'paulo@beta.com');
        $vendas = $this->sector('Vendas', $paulo);
        $this->actingAs($this->ana)->post(route('company.invites.store'), ['email' => 'bia@acme.com', 'role' => 'member', 'sectors' => [$ti->id]]);

        // Um setor da Beta posto na lista por fora do convite: o aceite o ignora, sem 500, e a conta entra só no TI.
        DB::table('company_invites')->update(['sector_ids' => json_encode([$ti->id, $vendas->id])]);
        $bia = $this->accept();

        $this->assertSame(['TI'], $bia->sectors()->pluck('name')->all());
        $this->assertSame(0, DB::table('sector_user')->where('sector_id', $vendas->id)->count());
    }

    public function test_sectors_of_another_company_or_unknown_are_refused(): void
    {
        $ti = $this->sector('TI');
        [, $paulo] = $this->company('Beta', 'paulo@beta.com');
        $vendas = $this->sector('Vendas', $paulo);

        foreach ([[$ti->id, $vendas->id], [999999]] as $sectors) {
            $this->actingAs($this->ana)->post(route('company.invites.store'), ['email' => 'bia@acme.com', 'role' => 'member', 'sectors' => $sectors])
                ->assertSessionHas('error', 'Escolha setores da empresa.');
        }
        $this->actingAs($this->ana)->post(route('company.invites.store'), ['email' => 'bia@acme.com', 'role' => 'member', 'sectors' => ['x']])
            ->assertSessionHasErrors('sectors.0');
        $this->assertSame(0, CompanyInvite::query()->count());
        $this->actingAs($this->ana)->get(route('company.accounts'))->assertDontSee('Vendas');

        // Sem setores, como antes; e sem setor nenhum na empresa, o formulário não mostra a escolha.
        $this->actingAs($paulo)->post(route('company.invites.store'), ['email' => 'caio@beta.com', 'role' => 'member'])->assertSessionHas('status');
        $this->assertSame([], CompanyInvite::query()->sole()->sector_ids);
        $this->assertSame([], $this->accept('Caio Lima')->sectors()->pluck('name')->all());
        [, $zeca] = $this->company('Gama', 'zeca@gama.com');
        $this->actingAs($zeca)->get(route('company.accounts'))->assertDontSee('Setores (opcional)');
    }
}
