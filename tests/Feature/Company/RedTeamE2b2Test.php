<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\User;
use App\Services\Company\CompanyRemoval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** Red Team da E2b2 (a poda passa a posse para a admin, P7). */
class RedTeamE2b2Test extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private Company $acme;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
    }

    private function gone(): User
    {
        return $this->companyAccount($this->acme, CompanyRole::SectorManager, ['deactivated_at' => now()->subDays(31)]);
    }

    // Evidência — a admin trocada no meio da poda (a outra transação já gravou): o que a conta criou vai para quem é a
    // admin na hora da passagem, nunca para a antiga nem para o vazio.
    public function test_evidencia_admin_trocada_no_meio_da_poda(): void
    {
        $caio = $this->gone();
        $bia = $this->companyAccount($this->acme);
        $profile = $this->profileOf($caio, $this->acme);

        $done = false;
        DB::listen(function ($query) use (&$done, $bia) {
            if (! $done && str_contains($query->sql, 'company_user') && str_contains($query->sql, 'for update')) {
                $done = true;
                DB::table('company_user')->where('user_id', $this->ana->id)->update(['role' => 'member']);
                DB::table('company_user')->where('user_id', $bia->id)->update(['role' => 'admin']);
            }
        });

        app(CompanyRemoval::class)->purge();

        $this->assertFalse(User::query()->whereKey($caio->id)->exists());
        $this->assertSame($bia->id, $profile->fresh()->user_id);
    }

    // Evidência (para a E2c2/E2d): o item da conta de empresa sem carimbo (company_id nulo, como o código no ar ainda
    // grava) não passa para a admin: a poda o apaga em cascata. Carimbar na criação e o que já existe antes da E7c.
    public function test_evidencia_item_sem_carimbo_vai_com_a_conta(): void
    {
        $caio = $this->gone();
        $stamped = $this->profileOf($caio, $this->acme);
        $unstamped = AlertProfile::query()->create(['user_id' => $caio->id, 'status' => 'active']);

        app(CompanyRemoval::class)->purge();

        $this->assertSame($this->ana->id, $stamped->fresh()->user_id);
        $this->assertNull($unstamped->fresh(), 'O item sem carimbo agora fica: atualize a evidência.');
    }
}
