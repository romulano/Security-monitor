<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\GithubMonitor;
use App\Models\LookalikeWatch;
use App\Models\User;
use App\Services\Company\CompanyStamp;
use App\Services\Company\Visibility;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** E2g: o carimbo retroativo do que conta de empresa criou sem `company_id` (comando e migration). */
class CompanyStampTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private User $bruno;

    private User $personal;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->personal = User::factory()->create();
    }

    /** O que a conta criou antes das PRs de leitura: sem empresa nem setor (o trigger da E2b0 deixa, por ser nulo). @return list<\Illuminate\Database\Eloquent\Model> */
    private function unstamped(User $owner): array
    {
        return [$this->profileOf($owner), $this->watchOf($owner), $this->monitorOf($owner)];
    }

    public function test_the_command_stamps_company_items_and_leaves_personal_ones(): void
    {
        $items = [...$this->unstamped($this->ana), $this->profileOf($this->bruno), $this->watchOf($this->bruno)];
        $mine = $this->unstamped($this->personal);
        // A conta removida (desativada) continua da empresa: o que ela criou também é da empresa.
        $off = $this->companyAccount($this->acme, state: ['deactivated_at' => now()]);
        $items[] = $this->monitorOf($off);
        // Antes do carimbo, ninguém vê (nem quem criou): é o que o carimbo resolve.
        $this->assertFalse(app(Visibility::class)->sees($this->ana, $items[0]));

        $this->artisan('secmon:company-stamp --dry-run')->expectsOutput('Seriam carimbados: 2 alerta(s), 2 Radar(es), 2 monitoramento(s) do GitHub.')->assertSuccessful();
        $this->assertSame(0, AlertProfile::query()->whereNotNull('company_id')->count(), 'O --dry-run só conta.');

        $this->artisan('secmon:company-stamp')->expectsOutput('Carimbados: 2 alerta(s), 2 Radar(es), 2 monitoramento(s) do GitHub.')->assertSuccessful();

        foreach ($items as $item) {
            $this->assertSame([$this->acme->id, null], [$item->fresh()->company_id, $item->fresh()->sector_id], $item::class);
        }
        foreach ($mine as $item) {
            $this->assertNull($item->fresh()->company_id, 'A conta pessoal não muda.');
        }
        $this->refreshVisibility();
        $this->assertTrue(app(Visibility::class)->sees($this->ana, $items[0]->fresh()));
        $this->assertTrue(app(Visibility::class)->manages($this->ana, $items[3]->fresh()));

        // De novo: nada a fazer.
        $this->artisan('secmon:company-stamp')->expectsOutput('Carimbados: 0 alerta(s), 0 Radar(es), 0 monitoramento(s) do GitHub.')->assertSuccessful();
    }

    public function test_it_only_fills_the_empty_ones_with_the_owners_company_as_the_trigger_wants(): void
    {
        [$beta] = $this->activeCompany('Beta', 'paulo@beta.com');
        $sector = $this->companySector($this->acme, 'TI');
        $stamped = $this->watchOf($this->bruno, $this->acme, $sector);
        $loose = $this->watchOf($this->bruno);

        app(CompanyStamp::class)->run();

        // O já carimbado (com setor) fica como está; o nulo ganha a empresa do dono, sem setor.
        $this->assertSame([$this->acme->id, $sector->id], [$stamped->fresh()->company_id, $stamped->fresh()->sector_id]);
        $this->assertSame([$this->acme->id, null], [$loose->fresh()->company_id, $loose->fresh()->sector_id]);

        // E o trigger da E2b0 segue valendo: carimbar com outra empresa, ou carimbar o pessoal, o banco recusa.
        foreach ([[$loose, $beta->id], [$this->watchOf($this->personal), $this->acme->id]] as [$item, $company]) {
            try {
                DB::transaction(fn () => DB::table('lookalike_watches')->where('id', $item->id)->update(['company_id' => $company]));
                $this->fail('O banco aceitou a empresa errada.');
            } catch (QueryException) {
            }
        }
    }

    // O único parcial da E2b0 vale só para `company_id` nulo: uma conta tem no máximo um GitHub sem carimbo, e o carimbo
    // nunca colide com os que ela já tem com empresa.
    public function test_github_monitors_keep_the_partial_unique_index(): void
    {
        $new = $this->monitorOf($this->bruno, $this->acme);
        $old = $this->monitorOf($this->bruno);

        try {
            DB::transaction(fn () => $this->monitorOf($this->bruno));
            $this->fail('Dois monitoramentos sem empresa para a mesma conta.');
        } catch (QueryException) {
        }

        $this->assertSame(['alertas' => 0, 'radares' => 0, 'github' => 1], app(CompanyStamp::class)->run());
        $this->assertSame([$this->acme->id, $this->acme->id], [$new->fresh()->company_id, $old->fresh()->company_id]);
        $this->assertSame(2, GithubMonitor::query()->where('company_id', $this->acme->id)->count());

        // Pessoal: um só, sem empresa, como sempre.
        $this->monitorOf($this->personal);
        app(CompanyStamp::class)->run();
        $this->assertSame(1, GithubMonitor::query()->whereNull('company_id')->count());
    }

    public function test_the_data_migration_does_the_same_and_is_idempotent(): void
    {
        [$profile, $watch, $monitor] = $this->unstamped($this->bruno);
        $personal = $this->profileOf($this->personal);
        $migration = require database_path('migrations/2026_12_13_000100_stamp_company_items.php');

        $migration->up();
        $migration->up();

        $this->assertSame([$this->acme->id, $this->acme->id, $this->acme->id], [$profile->fresh()->company_id, $watch->fresh()->company_id, $monitor->fresh()->company_id]);
        $this->assertNull($personal->fresh()->company_id);
        $this->assertSame(1, LookalikeWatch::query()->whereNotNull('company_id')->count());
    }
}
