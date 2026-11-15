<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\CompanyAuditEntry;
use App\Models\GithubMonitor;
use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;
use App\Models\User;
use App\Services\Company\CompanyHandover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** P7 (DESENHO-5.2 §2.5): a poda dos 30 dias passa para a admin o que a conta criou na empresa, em vez de apagar. */
class CompanyHandoverTest extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_the_purge_keeps_what_the_account_created_for_the_company(): void
    {
        [$acme, $ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $ti = $this->companySector($acme, 'TI');
        $bia = $this->companyAccount($acme, CompanyRole::SectorManager, ['deactivated_at' => now()->subDays(31)]);
        $this->joinSector($bia, $ti, manager: true);

        $profile = $this->profileOf($bia, $acme, $ti);
        $newsDomain = $profile->domains()->create(['user_id' => $bia->id, 'domain' => 'acme.com.br', 'verify_token' => str_repeat('a', 32)]);
        $watch = $this->watchOf($bia, $acme);
        $monitor = $this->monitorOf($bia, $acme, $ti);
        $children = $this->childrenOf($profile, $watch, $monitor);
        // A admin já tem o GitHub dela: o da conta apagada não colide (o único é só da conta pessoal).
        $this->monitorOf($ana, $acme);

        $this->artisan('secmon:company-purge')->expectsOutputToContain('Apagadas: 1')->assertSuccessful();

        $this->assertModelMissing($bia);
        foreach ([$profile, $watch, $monitor, $newsDomain, $watch->domain] as $item) {
            $this->assertSame($ana->id, $item->fresh()?->user_id, $item::class);
        }
        foreach ($children as $class => $child) {
            $this->assertModelExists($child);
        }
        $this->assertSame($ti->id, $profile->fresh()->sector_id);
        $this->assertSame(['itens passados à admin' => 6], CompanyAuditEntry::query()->where('action', 'member.purged')->sole()->data);
    }

    public function test_personal_things_still_go_with_the_account_and_without_another_admin_nothing_is_erased(): void
    {
        [$acme, $ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $pedro = User::factory()->create();
        $personal = $this->profileOf($pedro);
        $caio = $this->companyAccount($acme, state: ['deactivated_at' => now()->subDays(31)]);
        $mine = $this->profileOf($caio, $acme);

        $this->assertSame(1, app(CompanyHandover::class)->toAdmin($this->memberRow($caio)));
        $this->assertSame($ana->id, $mine->fresh()->user_id);
        $this->assertSame($pedro->id, $personal->fresh()->user_id);

        // A própria admin (nunca desativada pela tela) não passa nada para si: recusa, e a poda deixa a conta.
        $this->expectException(LogicException::class);
        app(CompanyHandover::class)->toAdmin($this->memberRow($ana));
    }

    public function test_only_what_the_purged_account_created_moves(): void
    {
        [$acme, $ana] = $this->activeCompany('Acme', 'ana@acme.com');
        [$beta, $paulo] = $this->activeCompany('Beta', 'paulo@beta.com');
        $caio = $this->companyAccount($acme, state: ['deactivated_at' => now()->subDays(31)]);
        $dani = $this->companyAccount($acme);
        $his = $this->profileOf($caio, $acme);
        $colleague = $this->profileOf($dani, $acme);
        $foreign = $this->profileOf($paulo, $beta);

        $this->assertSame(1, app(CompanyHandover::class)->toAdmin($this->memberRow($caio)));

        $this->assertSame([$ana->id, $dani->id, $paulo->id], [$his->fresh()->user_id, $colleague->fresh()->user_id, $foreign->fresh()->user_id]);
        // A empresa do item não muda (a trava da E2b0 também não deixaria).
        $this->assertSame($acme->id, $his->fresh()->company_id);
        $this->assertSame(0, LookalikeWatch::query()->where('user_id', $ana->id)->count() + GithubMonitor::query()->where('user_id', $ana->id)->count() + MonitoredDomain::query()->where('user_id', $ana->id)->count());
    }
}
