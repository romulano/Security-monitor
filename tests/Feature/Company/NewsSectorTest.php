<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\CompanyAuditEntry;
use App\Models\NewsCase;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * E2c2 (R4, R14, P1; DESENHO-5.2 §2.1): o alerta de Notícias de empresa nasce no setor escolhido no "Novo alerta"
 * (o sector-choice do Radar), troca de setor no cartão com a Atividade (ItemSectors), avisa quando ficaria mudo até a
 * E5b e mostra o setor. "Sem setor" é só da admin; membro não cria nem configura; copiar exige configurar a origem.
 */
class NewsSectorTest extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private User $bia;

    private User $caio;

    private Sector $ti;

    private Sector $fin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->fin = $this->companySector($this->acme, 'Financeiro');
        $this->bia = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bia, $this->ti, manager: true);
        $this->caio = $this->companyAccount($this->acme);
        $this->joinSector($this->caio, $this->ti);
    }

    private function store(User $user, array $data = [])
    {
        return $this->actingAs($user)->from(route('alerts.news'))->post(route('alerts.store'), $data);
    }

    private function latestOf(User $user): ?AlertProfile
    {
        return AlertProfile::query()->where('user_id', $user->id)->latest('id')->first();
    }

    private function change(User $user, AlertProfile $profile, string $sector)
    {
        return $this->actingAs($user)->from(route('alerts.news'))->put(route('alerts.sector', ['alerta' => $profile->id]), ['setor' => $sector]);
    }

    /** O passo 1 de um alerta novo de empresa (sem ?alerta=), gravado. */
    private function start(User $user, array $data = [])
    {
        return $this->actingAs($user)->from(route('alerts.interview', ['passo' => 1]))
            ->put(route('alerts.interview.update', ['passo' => 1]), $data + ['answers' => ['alert_name' => 'Loja nova', 'company_name' => 'Acme']]);
    }

    public function test_the_new_alert_is_born_in_step_one_with_the_chosen_sector(): void
    {
        // Abrir o passo 1 não cria nada; a tela traz o setor (o responsável de um setor só, já escolhido).
        // O nome do campo não se confunde com o "Setor de atuação" (o ramo da empresa) do mesmo passo (Designer, 10b).
        $this->actingAs($this->bia)->get(route('alerts.interview', ['passo' => 1]))->assertOk()->assertSee('Setor da empresa que vê este alerta: TI');
        $this->actingAs($this->ana)->get(route('alerts.interview', ['passo' => 1]))->assertOk()->assertSee('Sem setor (toda a empresa vê)');
        $this->assertSame(0, AlertProfile::query()->count());

        // Gravar o passo 1 cria o alerta no setor e segue para o passo 2 com o ?alerta=.
        $response = $this->start($this->bia, ['setor' => $this->ti->id]);
        $response->assertRedirect(route('alerts.interview', ['passo' => 2, 'alerta' => $this->latestOf($this->bia)->id]));
        $this->assertSame([$this->acme->id, $this->ti->id, 'Loja nova'], [$this->latestOf($this->bia)->company_id, $this->latestOf($this->bia)->sector_id, $this->latestOf($this->bia)->answerMap()['alert_name'] ?? null]);
        $this->start($this->ana)->assertRedirect();
        $this->assertSame([$this->acme->id, null], [$this->latestOf($this->ana)->company_id, $this->latestOf($this->ana)->sector_id]);

        // O responsável não deixa sem setor (P1) nem cria em setor que não gerencia: nada fica de rascunho.
        $this->start($this->bia)->assertSessionHas('error');
        $this->start($this->bia, ['setor' => $this->fin->id])->assertSessionHas('error');
        $this->start($this->bia, ['setor' => 'x'])->assertSessionHasErrors('setor');
        $this->actingAs($this->bia)->put(route('alerts.interview.update', ['passo' => 1]), ['setor' => $this->ti->id, 'answers' => ['alert_name' => 'Sem empresa']])->assertSessionHasErrors();
        $this->assertSame(1, AlertProfile::query()->where('user_id', $this->bia->id)->count());

        // Duplo clique no passo 1 (o data-once segura no navegador; o servidor também): o mesmo envio cai no rascunho
        // que o primeiro criou; um alerta diferente logo depois nasce normalmente.
        $this->actingAs($this->bia)->get(route('alerts.interview', ['passo' => 1]))->assertSee('data-once', false);
        $this->start($this->bia, ['setor' => $this->ti->id])->assertRedirect();
        $this->assertSame(1, AlertProfile::query()->where('user_id', $this->bia->id)->count());
        $this->actingAs($this->bia)->put(route('alerts.interview.update', ['passo' => 1]), ['setor' => $this->ti->id, 'answers' => ['alert_name' => 'Outra loja', 'company_name' => 'Acme']])->assertRedirect();
        $this->assertSame(2, AlertProfile::query()->where('user_id', $this->bia->id)->count());

        // O membro não abre nem grava o passo 1 de um alerta novo; os outros passos sem ?alerta= são 404 para a empresa.
        $this->actingAs($this->caio)->get(route('alerts.interview', ['passo' => 1]))->assertNotFound();
        $this->start($this->caio, ['setor' => $this->ti->id])->assertNotFound();
        $this->actingAs($this->ana)->get(route('alerts.interview', ['passo' => 2]))->assertNotFound();
        $this->assertNull($this->latestOf($this->caio));

        // As telas: quem cria vê o "Novo alerta" (que leva ao passo 1); o membro, nem ele nem o "Integrar Notícias".
        $this->actingAs($this->bia)->get(route('alerts.news'))->assertOk()->assertSee(route('alerts.interview', ['passo' => 1]), false);
        $this->actingAs($this->caio)->get(route('alerts.news'))->assertOk()->assertDontSee('Novo alerta');
        $this->actingAs($this->caio)->get(route('alerts.integrations'))->assertOk()->assertDontSee('Integrar Notícias');
    }

    public function test_the_copy_also_chooses_the_sector(): void
    {
        $source = $this->profileOf($this->bia, $this->acme, $this->ti);
        $source->forceFill(['completed_at' => now()])->save();

        $this->store($this->bia, ['copiar' => $source->id, 'setor' => $this->ti->id])->assertRedirect();
        $this->assertSame($this->ti->id, $this->latestOf($this->bia)->sector_id);
        $this->store($this->bia, ['copiar' => $source->id])->assertSessionHas('error');
        $this->store($this->bia, ['copiar' => $source->id, 'setor' => $this->fin->id])->assertSessionHas('error');
        $this->actingAs($this->bia)->get(route('alerts.news'))->assertOk()->assertSee('Copiar um alerta');
    }

    public function test_changing_the_sector_is_audited_and_limited_to_what_the_account_may_use(): void
    {
        $profile = $this->profileOf($this->ana, $this->acme);
        $profile->forceFill(['name' => 'Loja'])->save();

        $this->change($this->ana, $profile, (string) $this->ti->id)->assertRedirect()->assertSessionHas('status', 'Setor do alerta trocado.');
        $this->assertSame($this->ti->id, $profile->fresh()->sector_id);
        $entry = CompanyAuditEntry::query()->where('action', 'item.sector_changed')->sole();
        $this->assertSame([$this->acme->id, $this->ana->id, 'Loja'], [$entry->company_id, $entry->actor_id, $entry->target_label]);
        $this->assertEquals(['tipo' => 'Notícias', 'de' => 'sem setor', 'para' => 'TI'], $entry->data);

        // O responsável não deixa sem setor nem leva para um setor que não gerencia; quem só vê, ou é de fora, leva 404.
        $this->change($this->bia, $profile, 'sem')->assertSessionHas('error');
        $this->change($this->bia, $profile, (string) $this->fin->id)->assertSessionHas('error');
        $this->change($this->caio, $profile, (string) $this->ti->id)->assertNotFound();
        [, $paulo] = $this->activeCompany('Beta', 'paulo@beta.com');
        $this->change($paulo, $profile, 'sem')->assertNotFound();
        $this->assertSame($this->ti->id, $profile->fresh()->sector_id);

        // A admin deixa sem setor; o alerta da conta pessoal não tem setor (404).
        $this->change($this->ana, $profile, 'sem')->assertRedirect();
        $this->assertNull($profile->fresh()->sector_id);
        $pedro = User::factory()->create();
        $this->change($pedro, $this->profileOf($pedro), 'sem')->assertNotFound();
        $this->assertSame(2, CompanyAuditEntry::query()->where('action', 'item.sector_changed')->count());
    }

    public function test_the_card_shows_the_sector_warns_when_muted_and_hides_configuration_from_members(): void
    {
        $profile = $this->profileOf($this->bia, $this->acme, $this->ti);
        $profile->forceFill(['name' => 'Loja TI'])->save();
        $case = NewsCase::query()->create(['title' => 'Caso da loja', 'fingerprint' => Str::random(32), 'is_published' => true]);
        AlertMatch::query()->create(['profile_id' => $profile->id, 'case_id' => $case->id, 'kind' => 'new_case', 'criticity' => 'high', 'score' => 60, 'delivery_status' => 'sent', 'matched_at' => now()]);

        // A admin vê o setor e pode trocar; levar para Financeiro, onde quem criou não está, avisa que o alerta fica mudo.
        $this->actingAs($this->ana)->get(route('alerts.news'))->assertOk()
            ->assertSee('Setor: <span class="text-ink-200">TI</span>', false)
            ->assertSee('quem criou este alerta não está em Financeiro')
            ->assertDontSee('quem criou este alerta não está em TI')
            ->assertSee('data-confirm-tone="danger"', false)
            ->assertSee(route('alerts.pause', ['alerta' => $profile->id]), false)
            ->assertSeeInOrder(['Caso da loja', 'Setor TI']);

        // O membro vê o setor e o selo, mas nada de configurar.
        $this->actingAs($this->caio)->get(route('alerts.news'))->assertOk()
            ->assertSee('Setor: <span class="text-ink-200">TI</span>', false)
            ->assertDontSee('Trocar o setor')
            ->assertDontSee(route('alerts.pause', ['alerta' => $profile->id]), false);

        // A conta pessoal: nada de setor.
        $pedro = User::factory()->create();
        $this->profileOf($pedro);
        $this->actingAs($pedro)->get(route('alerts.news'))->assertOk()->assertDontSee('Trocar o setor')->assertDontSee('data-sector-badge', false);
    }

    public function test_copying_needs_to_configure_the_source(): void
    {
        $general = $this->profileOf($this->ana, $this->acme);
        $mine = $this->profileOf($this->bia, $this->acme, $this->ti);

        // A Bia vê o alerta da empresa toda, mas não o configura: não leva as respostas dele para o setor dela.
        $this->store($this->bia, ['setor' => $this->ti->id, 'copiar' => $general->id])->assertNotFound();
        $this->store($this->bia, ['setor' => $this->ti->id, 'copiar' => $mine->id])->assertRedirect();
        $this->store($this->ana, ['copiar' => $mine->id])->assertRedirect();
    }
}
