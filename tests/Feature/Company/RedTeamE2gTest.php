<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\User;
use App\Services\Company\CompanyStamp;
use App\Services\Company\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** Red Team da E2g (carimbo retroativo). Sem achado: as bordas de R12. */
class RedTeamE2gTest extends TestCase
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

    // Evidência — R12: a conta de empresa nasce na empresa (criar empresa e convidar recusam e-mail que já tem conta),
    // então não há item "de antes de entrar". A desativada leva o que criou para a empresa dela (a poda passa à admin
    // depois); a que perdeu a linha em company_user por baixo vira pessoal e o item fica dela, sem empresa.
    public function test_evidencia_desativada_e_conta_que_virou_pessoal(): void
    {
        $off = $this->companyAccount($this->acme, CompanyRole::Member, ['deactivated_at' => now()]);
        $gone = $this->companyAccount($this->acme);
        $offItem = AlertProfile::query()->create(['user_id' => $off->id, 'status' => 'active']);
        $goneItem = AlertProfile::query()->create(['user_id' => $gone->id, 'status' => 'active']);
        DB::table('company_user')->where('user_id', $gone->id)->delete();

        app(CompanyStamp::class)->run();

        $this->assertSame([$this->acme->id, null], [$offItem->fresh()->company_id, $offItem->fresh()->sector_id]);
        $this->assertNull($goneItem->fresh()->company_id);
        $this->assertTrue(app(Visibility::class)->sees($gone->fresh(), $goneItem->fresh()), 'A conta que virou pessoal perdeu o que criou.');
        $this->assertTrue(app(Visibility::class)->sees($this->ana, $offItem->fresh()));
        $this->assertSame(['alertas' => 0, 'radares' => 0, 'github' => 0], app(CompanyStamp::class)->run(true));
    }

    // Evidência — com a empresa da linha trocada por baixo (sem setor, o trigger da E2a deixa), o carimbo usa a empresa
    // de agora, a mesma que o trigger da E2b0 exige; o item já carimbado não muda de empresa depois.
    public function test_evidencia_empresa_trocada_por_baixo(): void
    {
        [$beta] = $this->activeCompany('Beta', 'paulo@beta.com');
        $caio = $this->companyAccount($this->acme);
        $stamped = AlertProfile::query()->create(['user_id' => $caio->id, 'status' => 'active']);
        app(CompanyStamp::class)->run();

        DB::table('company_user')->where('user_id', $caio->id)->update(['company_id' => $beta->id]);
        $late = AlertProfile::query()->create(['user_id' => $caio->id, 'status' => 'active']);
        app(CompanyStamp::class)->run();

        $this->assertSame($this->acme->id, $stamped->fresh()->company_id);
        $this->assertSame($beta->id, $late->fresh()->company_id);
    }
}
