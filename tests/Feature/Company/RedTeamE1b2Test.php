<?php

namespace Tests\Feature\Company;

use App\Jobs\SendEmail;
use App\Mail\CompanyEntryLink;
use App\Models\Company;
use App\Models\User;
use App\Services\Company\CompanyEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Red Team da E1b2 (entrada da admin e bloqueio da conta de empresa inativa). */
class RedTeamE1b2Test extends TestCase
{
    use RefreshDatabase;

    private User $secmon;

    protected function setUp(): void
    {
        parent::setUp();
        config(['secmon.enterprise.enabled' => true]);
        Queue::fake();
        $this->secmon = User::factory()->create(['is_admin' => true]);
    }

    private function company(): Company
    {
        $this->actingAs($this->secmon)->post(route('admin.companies.store'), ['name' => 'Acme', 'admin_name' => 'Ana', 'admin_email' => 'ana@acme.com']);
        $company = Company::query()->sole();
        $this->post(route('admin.companies.enable', $company));
        Auth::logout();

        return $company->fresh();
    }

    private function ana(): User
    {
        return User::query()->where('email', 'ana@acme.com')->sole();
    }

    private function lastLink(): string
    {
        return Queue::pushed(SendEmail::class)->filter(fn (SendEmail $job) => $job->mail instanceof CompanyEntryLink)->last()->mail->url;
    }

    // D1 — "Reenviar o link" × a admin escolhendo a senha: o reenviar sorteia uma senha por cima da que ela acabou de escolher.
    public function test_rt_d1_reenviar_nao_apaga_a_senha_que_a_admin_acabou_de_escolher(): void
    {
        $company = $this->company();
        $ana = $this->ana();
        $stamp = (fn (User $user) => $this->stamp($user))->call(app(CompanyEntry::class), $ana);

        // A admin conclui a entrada enquanto o painel já leu "ainda não entrou".
        $done = false;
        User::retrieved(function (User $user) use (&$done, $ana, $stamp) {
            if (! $done && $user->id === $ana->id) {
                $done = true;
                app(CompanyEntry::class)->complete($user, $stamp, 'senha-da-ana-123', app('session.store'));
                Auth::logout();
            }
        });

        try {
            app(CompanyEntry::class)->resend($company);
        } catch (\Throwable) {
            // recusar é o desejado
        } finally {
            User::flushEventListeners();
        }

        $this->assertTrue(Auth::validate(['email' => 'ana@acme.com', 'password' => 'senha-da-ana-123']), 'O reenviar trocou a senha que a admin acabou de escolher.');
    }

    // Evidência — a sessão aberta cai na próxima página quando a empresa é suspensa, desligada ou a conta desativada.
    public function test_evidencia_a_sessao_aberta_cai(): void
    {
        $company = $this->company();
        $this->post($this->lastLink(), ['password' => 'senha-da-ana-123', 'password_confirmation' => 'senha-da-ana-123']);
        $ana = $this->ana();
        $this->assertAuthenticatedAs($ana);

        foreach ([
            fn () => $company->forceFill(['suspended_at' => now()])->save(),
            fn () => $company->forceFill(['enterprise_enabled_at' => null])->save(),
            fn () => config(['secmon.enterprise.enabled' => false]),
            fn () => $ana->companyMembership->forceFill(['deactivated_at' => now()])->save(),
        ] as $i => $close) {
            $company->forceFill(['suspended_at' => null, 'enterprise_enabled_at' => now()])->save();
            $ana->companyMembership->forceFill(['deactivated_at' => null])->save();
            config(['secmon.enterprise.enabled' => true]);
            $this->actingAs($ana)->get(route('account.show'))->assertOk();

            $close();
            $this->get(route('account.show'))->assertRedirect(route('login'));
            $this->assertGuest();
            // O GET de qualquer área com login, e o POST também.
            $this->actingAs($ana)->put(route('account.update'), ['name' => 'Outra', 'email' => 'ana@acme.com'])->assertRedirect(route('login'));
            $this->assertSame('Ana', $ana->fresh()->name, "Caso {$i}: a conta fechada ainda gravou.");
        }
    }

    // Evidência — o link: uso único, morre no reenviar, não abre com sessão nem para conta pessoal, e a assinatura vem antes da conta.
    public function test_evidencia_o_link(): void
    {
        $this->company();
        $first = $this->lastLink();
        $this->get(str_replace('/entrar/'.$this->ana()->id, '/entrar/'.$this->secmon->id, $first))->assertNotFound();
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $first))->assertNotFound();

        $this->actingAs($this->secmon)->post(route('admin.companies.entry', Company::query()->sole()));
        Auth::logout();
        $this->get($first)->assertNotFound();
        $second = $this->lastLink();

        $this->actingAs(User::factory()->create())->get($second)->assertRedirect();
        Auth::logout();
        $this->post($second, ['password' => 'senha-da-ana-123', 'password_confirmation' => 'senha-da-ana-123'])->assertRedirect(route('account.show'));
        Auth::logout();
        $this->post($second, ['password' => 'outra-senha-456', 'password_confirmation' => 'outra-senha-456'])->assertNotFound();
        $this->assertTrue(Auth::validate(['email' => 'ana@acme.com', 'password' => 'senha-da-ana-123']));
    }
}
