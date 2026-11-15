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
use Database\Seeders\DemoCompanySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** Red Team da E2a3 (setores no convite e dados de demonstração). */
class RedTeamE2a3Test extends TestCase
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

    /** @return array{Company, User} */
    private function company(string $name, string $email): array
    {
        $company = app(Companies::class)->create($name, 'Admin '.$name, $email);
        app(Companies::class)->enable($company);
        $admin = User::query()->where('email', $email)->sole();
        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->companyMembership->forceFill(['joined_at' => now()])->save();

        return [$company, $admin->fresh()];
    }

    private function sector(string $name, ?User $admin = null): Sector
    {
        return app(Sectors::class)->create($admin ?? $this->ana, $name);
    }

    private function invite(string $email, string $role, array $sectors)
    {
        return $this->actingAs($this->ana)->post(route('company.invites.store'), ['email' => $email, 'role' => $role, 'sectors' => $sectors]);
    }

    private function accept(string $name)
    {
        auth()->logout();
        $link = Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteLink)->last()->mail->url;

        return $this->post($link, ['name' => $name, 'password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1']);
    }

    // P1 — o seeder recusa só APP_ENV=production: um servidor com "prod", "staging" ou "homolog" ganharia 10 contas
    // (a admin da empresa inclusive) com a senha pública `senha-de-demo-123`.
    public function test_rt_p1_o_seeder_de_demo_so_roda_no_laboratorio(): void
    {
        foreach (['prod', 'staging', 'homolog'] as $env) {
            $this->app['env'] = $env;

            try {
                (new DemoCompanySeeder)->run();
            } catch (RuntimeException) {
            }

            $this->assertSame(0, Company::query()->where('name', DemoCompanySeeder::COMPANY)->count(), "Rodou com APP_ENV={$env}.");
        }
    }

    // P2 — sector_ids malformado no jsonb (escalar, null, item não inteiro): o aceite dá 500 e a pessoa fica sem conta.
    public function test_rt_p2_sector_ids_malformado_nao_derruba_o_aceite(): void
    {
        $ti = $this->sector('TI');

        foreach (['"5"', '[1, "x", null, {"a": 2}]', '{"a": 1}', 'null'] as $i => $json) {
            $this->invite("p{$i}@acme.com", 'member', [$ti->id])->assertSessionHas('status');
            $invite = CompanyInvite::query()->where('email', "p{$i}@acme.com")->sole();

            try {
                DB::transaction(fn () => DB::table('company_invites')->where('id', $invite->id)->update(['sector_ids' => DB::raw("'{$json}'::jsonb")]));
            } catch (QueryException) {
                // O banco recusa o que não é lista: o aceite nem chega a ver.
            }

            $this->accept("Pessoa {$i}")->assertRedirect();
            $this->assertNotNull(CompanyInvite::query()->find($invite->id)->accepted_at, "Aceite caiu com {$json}.");
        }
    }

    // Evidência — is_manager só com o papel; repetidos viram um; outra empresa, desconhecido, zero, negativo, gigante
    // e texto são recusados; o setor que mudou de nome entra pelo id.
    public function test_evidencia_papel_ids_e_setor_renomeado(): void
    {
        $ti = $this->sector('TI');
        $rh = $this->sector('RH');
        [, $paulo] = $this->company('Beta', 'paulo@beta.com');
        $vendas = $this->sector('Vendas', $paulo);

        foreach ([[$vendas->id], [$ti->id, $vendas->id], [0], [-1], ['99999999999999999999'], ['1 or 1=1'], [[1]]] as $bad) {
            $this->invite('x@acme.com', 'member', $bad)->assertRedirect();
        }
        $this->assertSame(0, CompanyInvite::query()->count());

        $this->invite('bia@acme.com', 'member', [$ti->id, $ti->id, (string) $rh->id])->assertSessionHas('status');
        app(Sectors::class)->rename($this->ana, $ti, 'Tecnologia');
        $this->accept('Bia')->assertRedirect();
        $bia = User::query()->where('name', 'Bia')->sole();
        $this->assertSame([$ti->id => false, $rh->id => false], DB::table('sector_user')->where('user_id', $bia->id)->orderBy('sector_id')->pluck('is_manager', 'sector_id')->all());

        $this->invite('caio@acme.com', 'sector_manager', [$ti->id])->assertSessionHas('status');
        $this->accept('Caio')->assertRedirect();
        $caio = User::query()->where('name', 'Caio')->sole();
        $this->assertSame(['Tecnologia'], $caio->managedSectors()->pluck('name')->all());
        $this->assertSame(0, DB::table('sector_user')->where('sector_id', $vendas->id)->count());
    }
}
