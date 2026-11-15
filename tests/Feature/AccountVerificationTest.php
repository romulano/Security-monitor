<?php

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Mail\VerifyEmail;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Cadastro aberto: sem convite, com o captcha do formulário (TurnstileTest) e o
 * e-mail confirmado por um link antes dos alertas; trocar de e-mail pede a confirmação de novo; contas antigas não perdem acesso.
 */
class AccountVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /** @param  array<string, mixed>  $overrides */
    private function signUp(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Maria',
            'email' => 'maria@example.com',
            'password' => 'minha-senha-123',
            'password_confirmation' => 'minha-senha-123',
        ];
    }

    private function link(User $user, ?string $email = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), ['user' => $user->id, 'hash' => sha1($email ?? $user->email)]);
    }

    /** O link que saiu no e-mail de confirmação (o job está na fila falsa). */
    private function sentLink(): string
    {
        $job = Queue::pushed(SendEmail::class)->last();
        $this->assertInstanceOf(VerifyEmail::class, $job->mail);

        return $job->mail->url;
    }

    public function test_anyone_creates_an_account_without_an_invite_and_gets_the_link(): void
    {
        $this->post(route('register'), $this->signUp())
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'Conta criada. Enviamos um link de confirmação para maria@example.com.');

        $user = User::where('email', 'maria@example.com')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('email_messages', ['user_id' => $user->id, 'kind' => 'confirmacao', 'to_address' => 'maria@example.com', 'subject' => 'Confirme o seu e-mail no Security Monitor']);
        $this->assertStringContainsString('/conta/confirmar-email/'.$user->id.'/'.sha1('maria@example.com').'?expires=', $this->sentLink());
    }

    public function test_an_old_invite_code_in_the_form_is_ignored(): void
    {
        $this->post(route('register'), $this->signUp(['invite' => 'codigo-de-um-convite-antigo']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('verification.notice'));

        $this->assertAuthenticatedAs(User::where('email', 'maria@example.com')->sole());
    }

    public function test_the_alerts_wait_for_the_confirmation(): void
    {
        $this->post(route('register'), $this->signUp());

        $this->get(route('alerts.index'))->assertRedirect(route('verification.notice'));
        $this->get(route('alerts.integrations'))->assertRedirect(route('verification.notice'));
        $this->get(route('verification.notice'))->assertOk()->assertSee('maria@example.com')->assertSee('Enviar outro link');
        $this->get(route('account.show'))->assertOk()->assertSee('Confirme o seu e-mail para usar os alertas.');
    }

    public function test_the_link_confirms_the_email_and_opens_the_alerts(): void
    {
        $this->post(route('register'), $this->signUp());
        $user = User::where('email', 'maria@example.com')->sole();

        $this->get($this->sentLink())
            ->assertRedirect(route('alerts.index'))
            ->assertSessionHas('status', 'E-mail confirmado. Os alertas estão liberados.');

        $this->assertNotNull($user->fresh()->email_verified_at);

        // A próxima requisição relê a conta da sessão (no teste, o guard guardaria a da primeira).
        auth()->forgetUser();
        $this->get(route('alerts.index'))->assertOk();
        $this->get(route('verification.notice'))->assertRedirect(route('alerts.index'));
        $this->get(route('account.show'))->assertDontSee('Confirme o seu e-mail para usar os alertas.');
    }

    public function test_a_forged_expired_or_outdated_link_does_not_confirm(): void
    {
        $user = User::factory()->unverified()->create();

        // Sem assinatura, com outra conta no lugar ou vencido: o `signed` recusa antes de procurar a conta.
        $this->get(route('verification.verify', ['user' => $user->id, 'hash' => sha1($user->email)]))->assertForbidden();
        $this->get(str_replace("/confirmar-email/{$user->id}/", '/confirmar-email/999999/', $this->link($user)))->assertForbidden();
        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['user' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($expired)->assertForbidden();

        // Assinado, mas para um e-mail que não é mais o da conta: 404.
        $this->get($this->link($user, 'antigo@example.com'))->assertNotFound();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_the_link_works_without_logging_in(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->link($user))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'E-mail confirmado. Os alertas estão liberados. Entre para usar.');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest();
    }

    public function test_the_link_is_used_once_and_then_only_says_it_was_confirmed(): void
    {
        $user = User::factory()->unverified()->create();
        $link = $this->link($user);

        $this->get($link)->assertSessionHas('status', 'E-mail confirmado. Os alertas estão liberados. Entre para usar.');
        $confirmedAt = $user->fresh()->email_verified_at;
        $this->travel(10)->minutes();

        Event::fake([Verified::class]);
        $this->get($link)->assertRedirect(route('login'))->assertSessionHas('status', 'Este e-mail já estava confirmado. Entre para usar.');
        Event::assertNotDispatched(Verified::class);
        $this->assertTrue($confirmedAt->equalTo($user->fresh()->email_verified_at));
    }

    public function test_another_link_can_be_asked_and_it_is_rate_limited(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', "Enviamos um link novo para {$user->email}.");
        $this->actingAs($user)->post(route('verification.send'));
        $this->actingAs($user)->post(route('verification.send'));
        $this->actingAs($user)->post(route('verification.send'))->assertTooManyRequests();

        $this->assertSame(3, EmailMessage::where('user_id', $user->id)->where('kind', 'confirmacao')->count());
    }

    public function test_a_confirmed_account_gets_no_new_link(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('verification.send'))->assertRedirect(route('alerts.index'));

        $this->assertSame(0, EmailMessage::count());
    }

    public function test_changing_the_email_asks_for_the_confirmation_again(): void
    {
        $user = User::factory()->create(['password' => 'minha-senha-123']);

        $this->actingAs($user)->put(route('account.update'), [
            'name' => $user->name,
            'email' => 'novo@example.com',
            'current_password' => 'minha-senha-123',
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('novo@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('email_messages', ['user_id' => $user->id, 'kind' => 'confirmacao', 'to_address' => 'novo@example.com']);
        $this->actingAs($user)->get(route('alerts.index'))->assertRedirect(route('verification.notice'));

        // Só o nome não mexe na confirmação.
        $user->markEmailAsVerified();
        $this->actingAs($user)->put(route('account.update'), ['name' => 'Outro nome', 'email' => 'novo@example.com'])->assertSessionHasNoErrors();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_the_accounts_from_before_count_as_confirmed(): void
    {
        DB::table('users')->insert([
            'name' => 'Antiga', 'email' => 'antiga@example.com', 'password' => 'x', 'is_admin' => false,
            'created_at' => '2026-10-20 12:00:00', 'updated_at' => '2026-10-20 12:00:00', 'email_verified_at' => null,
        ]);

        $migration = require database_path('migrations/2026_11_08_000200_mark_existing_accounts_as_verified.php');
        $migration->up();

        $this->assertSame('2026-10-20 12:00:00', DB::table('users')->where('email', 'antiga@example.com')->value('email_verified_at'));
    }

    public function test_the_admin_sees_who_has_not_confirmed(): void
    {
        User::factory()->unverified()->create(['email' => 'pendente@example.com']);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.users'))
            ->assertOk()
            ->assertSee('pendente@example.com')
            ->assertSee('e-mail não confirmado');
    }

    public function test_the_pages_no_longer_say_invite_only(): void
    {
        $this->get(route('register'))->assertOk()->assertDontSee('convite')->assertDontSee('name="invite"', false);
        $this->get(route('login'))->assertSee('Ainda não tem conta?');
        $this->get(route('about'))->assertDontSee('Pedir convite');
        $this->get(route('terms'))->assertSee('O cadastro é aberto')->assertDontSee('O cadastro é feito só por convite')->assertDontSee('código de convite');
        $this->get(route('privacy'))->assertSee('E-mails que enviamos')->assertSee('Registro dos e-mails enviados');
    }
}
