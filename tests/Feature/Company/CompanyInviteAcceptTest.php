<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Jobs\SendEmail;
use App\Mail\CompanyInviteAccepted;
use App\Mail\CompanyInviteLink;
use App\Models\Company;
use App\Models\CompanyInvite;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\CompanyInvites;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** E1c1: o aceite do convite (R12, D4, A8): a página do link, a conta e o vínculo na mesma transação. */
class CompanyInviteAcceptTest extends TestCase
{
    use RefreshDatabase;

    private const GONE = 'Este convite já foi usado, foi cancelado ou venceu';

    private Company $acme;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        config(['secmon.enterprise.enabled' => true]);
        Queue::fake();
        [$this->acme, $this->ana] = $this->company('Acme', 'ana@acme.com');
    }

    /** @return array{Company, User} uma empresa ativa e a admin dela */
    private function company(string $name, string $email): array
    {
        $company = app(Companies::class)->create($name, 'Admin '.$name, $email);
        app(Companies::class)->enable($company);

        return [$company, User::query()->where('email', $email)->sole()];
    }

    private function invites(): CompanyInvites
    {
        return app(CompanyInvites::class);
    }

    private function invite(string $email = 'bia@acme.com', ?Company $company = null): string
    {
        $company ??= $this->acme;
        $this->invites()->invite($company, $company->members()->with('user')->first()->user, $email, CompanyRole::Member);

        return $this->lastLink();
    }

    private function lastLink(): string
    {
        return Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteLink)->last()->mail->url;
    }

    private function accept(string $link, string $name = 'Bia Souza'): TestResponse
    {
        return $this->post($link, ['name' => $name, 'password' => 'senha-da-bia-1', 'password_confirmation' => 'senha-da-bia-1']);
    }

    public function test_accepting_creates_the_account_and_the_membership_together(): void
    {
        $link = $this->invite();

        $this->get($link)->assertOk()->assertSee('Acme')->assertSee('bia@acme.com')->assertSee('autocomplete="username"', false);
        $this->accept($link)->assertRedirect(route('account.show'));

        $bia = User::query()->where('email', 'bia@acme.com')->sole();
        $this->assertAuthenticatedAs($bia);
        $this->assertSame('Bia Souza', $bia->name);
        $this->assertTrue(Hash::check('senha-da-bia-1', $bia->password));
        $this->assertNotNull($bia->email_verified_at);
        $this->assertFalse((bool) $bia->is_admin);
        $this->assertSame(CompanyRole::Member, $bia->companyRole());
        $this->assertTrue($bia->company->is($this->acme));
        $this->assertNotNull($bia->companyMembership->joined_at);
        $this->assertSame($bia->id, CompanyInvite::query()->sole()->accepted_user_id);

        // Quem administra recebe o aviso.
        $told = Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteAccepted)->sole();
        $this->assertSame('Bia Souza', $told->mail->member);
        $this->assertSame('ana@acme.com', DB::table('email_messages')->where('kind', 'empresa-convite-aceito')->value('to_address'));
    }

    public function test_a_used_link_is_gone(): void
    {
        $link = $this->invite();
        $this->accept($link);
        auth()->logout();

        $this->get($link)->assertNotFound()->assertSee(self::GONE);
        $this->accept($link, 'Outra Pessoa')->assertNotFound();
        $this->assertSame(1, User::query()->where('email', 'bia@acme.com')->count());
        $this->assertSame(2, $this->acme->members()->count());
    }

    public function test_an_expired_or_cancelled_invite_is_gone_and_resending_renews_it(): void
    {
        $old = $this->invite();
        $invite = CompanyInvite::query()->sole();

        $this->travel(8)->days();
        $this->get($old)->assertNotFound()->assertSee(self::GONE);
        // O vencido não ocupa vaga.
        $this->assertSame(1, $this->invites()->seatsTaken($this->acme));

        $this->invites()->resend($invite);
        $new = $this->lastLink();
        $this->assertNotSame($old, $new);
        $this->get($old)->assertNotFound();
        $this->get($new)->assertOk();

        $this->invites()->cancel($invite->fresh());
        $this->get($new)->assertNotFound()->assertSee(self::GONE);
        $this->expectException(ActionRefused::class);
        $this->invites()->resend($invite->fresh());
    }

    public function test_a_wrong_token_or_another_companys_invite_never_lands_elsewhere(): void
    {
        $this->invite();
        [$beta] = $this->company('Beta', 'paulo@beta.com');
        $betaLink = $this->invite('bia@beta.com', $beta);

        $this->get(route('company.invite', str_repeat('a', 48)))->assertNotFound()->assertSee(self::GONE);
        $this->get('/empresa/convite/curto')->assertNotFound()->assertDontSee(self::GONE);

        $this->accept($betaLink, 'Bia Beta');
        $bia = User::query()->where('email', 'bia@beta.com')->sole();
        $this->assertTrue($bia->company->is($beta));
        $this->assertFalse($this->acme->members()->where('user_id', $bia->id)->exists());

        // Empresa suspensa: o convite não abre.
        auth()->logout();
        $link = $this->invite('caio@acme.com');
        app(Companies::class)->suspend($this->acme);
        $this->get($link)->assertNotFound()->assertSee(self::GONE);
        $this->accept($link)->assertNotFound();
        $this->assertFalse(User::query()->where('email', 'caio@acme.com')->exists());
    }

    public function test_the_eleventh_account_is_refused_counting_pending_invites(): void
    {
        foreach (range(1, 8) as $i) {
            $this->acme->members()->forceCreate(['user_id' => User::factory()->create()->id, 'role' => CompanyRole::Member, 'joined_at' => now()]);
        }

        // 9 contas + 1 convite = 10: o 11º não cabe.
        $link = $this->invite('decima@acme.com');
        try {
            $this->invite('decima-primeira@acme.com');
            $this->fail('Coube a 11ª conta.');
        } catch (ActionRefused $e) {
            $this->assertStringContainsString('10 de 10', $e->getMessage());
        }

        // Cancelar libera a vaga.
        $this->invites()->cancel(CompanyInvite::query()->sole());
        $this->invite('decima-primeira@acme.com');
        $this->assertSame(10, $this->invites()->seatsTaken($this->acme));

        // Com a empresa cheia de contas (uma entrou por outro caminho), o convite pendente também não entra.
        $this->acme->members()->forceCreate(['user_id' => User::factory()->create()->id, 'role' => CompanyRole::Member, 'joined_at' => now()]);
        $this->accept($this->lastLink())->assertSessionHas('error', fn ($e) => str_contains($e, 'de 10 contas'));
        $this->assertFalse(User::query()->where('email', 'decima-primeira@acme.com')->exists());
        $this->assertGuest();
        $this->assertNotNull($link);
    }

    public function test_a_personal_account_never_joins(): void
    {
        $personal = User::factory()->create(['email' => 'Pessoa@Gmail.com']);

        try {
            $this->invite('pessoa@gmail.com');
            $this->fail('Convidou uma conta pessoal.');
        } catch (ActionRefused $e) {
            $this->assertStringContainsString('convide outro e-mail', $e->getMessage());
        }
        $this->assertSame(0, CompanyInvite::query()->count());

        // A conta pessoal criada depois do convite também não é ligada (A8): o aceite é recusado.
        $link = $this->invite('carla@gmail.com');
        $carla = User::factory()->create(['email' => 'Carla@gmail.com', 'password' => 'senha-da-carla-1']);
        $this->accept($link)->assertRedirect(route('login'))->assertSessionHas('error', fn ($e) => str_contains($e, 'convide outro e-mail'));

        $this->assertGuest();
        $this->assertFalse($carla->fresh()->isCompanyAccount());
        $this->assertTrue(Hash::check('senha-da-carla-1', $carla->fresh()->password));
        $this->assertFalse($personal->fresh()->isCompanyAccount());
        $this->assertNull(CompanyInvite::query()->sole()->accepted_at);
    }

    // F3 do Red Team: a comparação de e-mail é sempre a do lower() do banco, também fora do ASCII.
    public function test_no_admin_invite_no_duplicate_and_the_form_is_checked(): void
    {
        try {
            $this->invites()->invite($this->acme, $this->ana, 'chefe@acme.com', CompanyRole::Admin);
            $this->fail('Convidou outra admin.');
        } catch (ActionRefused) {
            $this->addToAssertionCount(1);
        }

        $link = $this->invite();
        try {
            $this->invite('BIA@acme.com');
            $this->fail('Dois convites abertos para o mesmo e-mail.');
        } catch (ActionRefused $e) {
            $this->assertStringContainsString('Reenviar', $e->getMessage());
        }

        $this->from($link)->post($link, ['name' => '', 'password' => 'curta', 'password_confirmation' => 'outra'])
            ->assertSessionHasErrors(['name', 'password']);
        $this->assertFalse(User::query()->where('email', 'bia@acme.com')->exists());
    }

    public function test_without_the_global_key_the_invite_is_a_plain_404(): void
    {
        $link = $this->invite();
        config(['secmon.enterprise.enabled' => false]);

        $this->get($link)->assertNotFound()->assertDontSee(self::GONE);
        $this->accept($link)->assertNotFound();
        $this->assertFalse(User::query()->where('email', 'bia@acme.com')->exists());
    }

    public function test_a_logged_in_visitor_does_not_accept(): void
    {
        $link = $this->invite();

        $this->actingAs(User::factory()->create())->post($link, ['name' => 'X', 'password' => 'senha-da-bia-1', 'password_confirmation' => 'senha-da-bia-1'])
            ->assertRedirect(route('account.show'));
        $this->assertFalse(User::query()->where('email', 'bia@acme.com')->exists());
    }
}
