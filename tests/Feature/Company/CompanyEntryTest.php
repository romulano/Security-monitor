<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Jobs\SendEmail;
use App\Mail\CompanyEntryLink;
use App\Models\Company;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\Company\Companies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** E1b2: o link da admin da empresa (escolher a senha e confirmar o e-mail) e o bloqueio da conta de empresa inativa. */
class CompanyEntryTest extends TestCase
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

    private function createCompany(): Company
    {
        $this->actingAs($this->secmon)->post(route('admin.companies.store'), ['name' => 'Acme', 'admin_name' => 'Ana', 'admin_email' => 'ana@acme.com']);

        return Company::query()->sole();
    }

    private function ana(): User
    {
        return User::query()->where('email', 'ana@acme.com')->sole();
    }

    /** @return list<string> os links enviados, do mais antigo */
    private function links(): array
    {
        return Queue::pushed(SendEmail::class)
            ->filter(fn (SendEmail $job) => $job->mail instanceof CompanyEntryLink)
            ->map(fn (SendEmail $job) => $job->mail->url)->values()->all();
    }

    private function enabledCompany(): Company
    {
        $company = $this->createCompany();
        $this->actingAs($this->secmon)->post(route('admin.companies.enable', $company));
        auth()->logout();

        return $company->fresh();
    }

    private function choose(string $link, string $password = 'senha-forte-123'): TestResponse
    {
        return $this->post($link, ['password' => $password, 'password_confirmation' => $password]);
    }

    public function test_the_link_goes_out_when_the_company_key_is_turned_on(): void
    {
        $this->createCompany();
        $this->assertSame([], $this->links());
        $this->assertSame(0, EmailMessage::query()->where('kind', 'empresa-entrada')->count());

        $this->enabledCompany();

        $this->assertCount(1, $this->links());
        $this->assertSame('ana@acme.com', EmailMessage::query()->where('kind', 'empresa-entrada')->sole()->to_address);
        $this->assertStringContainsString('/empresa/entrar/'.$this->ana()->id, $this->links()[0]);
    }

    public function test_the_admin_chooses_the_password_once_and_comes_in_confirmed(): void
    {
        $company = $this->enabledCompany();
        $link = $this->links()[0];

        $this->get($link)->assertOk()->assertSee('Acme')->assertSee('ana@acme.com');

        $this->choose($link)->assertRedirect(route('account.show'));
        $ana = $this->ana();
        $this->assertAuthenticatedAs($ana);
        $this->assertTrue(Hash::check('senha-forte-123', $ana->password));
        $this->assertNotNull($ana->email_verified_at);
        $this->assertNotNull($ana->companyMembership->joined_at);
        $this->get(route('account.show'))->assertOk();

        // Uso único: o mesmo link não abre nem troca a senha de novo.
        auth()->logout();
        $this->get($link)->assertNotFound()->assertSee('Este link já foi usado ou venceu');
        $this->choose($link, 'outra-senha-456')->assertNotFound();
        $this->assertTrue(Hash::check('senha-forte-123', $this->ana()->password));

        // Quem já entrou não recebe outro link.
        $this->actingAs($this->secmon)->post(route('admin.companies.entry', $company))->assertSessionHas('error');
    }

    public function test_the_password_is_checked_like_the_sign_up(): void
    {
        $this->enabledCompany();
        $link = $this->links()[0];

        $this->from($link)->post($link, ['password' => 'curta', 'password_confirmation' => 'curta'])->assertSessionHasErrors('password');
        $this->from($link)->post($link, ['password' => 'senha-forte-123', 'password_confirmation' => 'outra-senha-123'])->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertNull($this->ana()->companyMembership->joined_at);
    }

    public function test_a_tampered_expired_or_foreign_link_does_not_open(): void
    {
        $this->enabledCompany();
        $link = $this->links()[0];
        $ana = $this->ana();

        // A mesma página amigável para todo motivo (adulterado, de outra conta, de conta pessoal, vencido, usado).
        $gone = 'Este link já foi usado ou venceu';
        $this->get(str_replace('stamp=', 'stamp=0', $link))->assertNotFound()->assertSee($gone);
        $this->get(str_replace('/entrar/'.$ana->id, '/entrar/'.$this->secmon->id, $link))->assertNotFound()->assertSee($gone);

        // Uma conta pessoal não tem link que valha, nem com assinatura verdadeira.
        $personal = User::factory()->create();
        $this->get(URL::temporarySignedRoute('company.entry', now()->addDay(), ['user' => $personal->id, 'stamp' => 'x']))->assertNotFound()->assertSee($gone);

        $this->travel(8)->days();
        $this->get($link)->assertNotFound()->assertSee($gone);
        $this->assertNull($ana->fresh()->companyMembership->joined_at);
    }

    public function test_the_link_needs_the_company_active_and_the_visitor_logged_out(): void
    {
        $company = $this->enabledCompany();
        $link = $this->links()[0];

        $this->actingAs(User::factory()->create())->get($link)->assertRedirect(route('account.show'));
        auth()->logout();

        app(Companies::class)->suspend($company);
        $this->get($link)->assertNotFound();
        app(Companies::class)->resume($company);
        $this->get($link)->assertOk();

        config(['secmon.enterprise.enabled' => false]);
        $this->get($link)->assertNotFound()->assertDontSee('Este link já foi usado');
        $this->choose($link)->assertNotFound();
        $this->assertGuest();
    }

    public function test_resending_kills_the_old_link(): void
    {
        $company = $this->enabledCompany();
        $old = $this->links()[0];

        $this->actingAs($this->secmon)->get(route('admin.companies.index'))->assertSee('ainda não entrou')->assertSee('Reenviar o link');
        $this->actingAs($this->secmon)->post(route('admin.companies.entry', $company))->assertSessionHas('status');
        auth()->logout();

        [, $new] = $this->links();
        $this->get($old)->assertNotFound();
        $this->choose($new)->assertRedirect(route('account.show'));

        // Com a empresa desligada, não reenvia.
        $other = app(Companies::class)->create('Outra', 'Beto', 'beto@outra.com');
        $this->actingAs($this->secmon)->post(route('admin.companies.entry', $other))->assertSessionHas('error', fn ($e) => str_contains($e, 'Ligue a chave'));
        $this->assertCount(2, $this->links());
    }

    public function test_a_company_account_only_uses_the_secmon_while_the_company_is_active(): void
    {
        $company = $this->enabledCompany();
        $this->choose($this->links()[0]);
        $companies = app(Companies::class);

        $member = User::factory()->create(['password' => 'senha-do-membro-1']);
        $company->members()->forceCreate(['user_id' => $member->id, 'role' => CompanyRole::Member, 'joined_at' => now()]);

        foreach ([
            'suspensa' => fn () => $companies->suspend($company),
            'ainda não está liberada' => fn () => $companies->disable($company),
            'ainda não está liberada ' => fn () => config(['secmon.enterprise.enabled' => false]),
        ] as $reason => $block) {
            $this->actingAs($member)->get(route('account.show'))->assertOk();

            $block();
            $this->actingAs($member)->get(route('account.show'))
                ->assertRedirect(route('login'))
                ->assertSessionHas('error', fn ($e) => str_contains($e, trim($reason)));
            $this->assertGuest();

            // A senha certa também não entra.
            $this->post(route('login'), ['email' => $member->email, 'password' => 'senha-do-membro-1']);
            $this->get(route('account.show'))->assertRedirect(route('login'));
            $this->assertGuest();

            config(['secmon.enterprise.enabled' => true]);
            $companies->resume($company->refresh());
            $companies->enable($company);
        }

        // Desativada pela admin (§4.6): fora, com a empresa ativa.
        $member->companyMembership->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($member->fresh())->get(route('account.show'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'desativada'));
    }

    public function test_a_personal_account_is_not_touched(): void
    {
        $this->enabledCompany();
        $personal = User::factory()->create();

        config(['secmon.enterprise.enabled' => false]);
        $this->actingAs($personal)->get(route('account.show'))->assertOk();
        $this->assertAuthenticatedAs($personal);
    }
}
