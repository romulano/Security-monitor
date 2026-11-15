<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Services\Auth\Reauthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Confirmar que é você pelo GitHub: só o GitHub já ligado à conta, comparado
 * pelo id, e nunca um vínculo novo pelo e-mail (condição B2 do Blue Team).
 */
class GithubReauthTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        config(['services.github.client_id' => 'Iv1.abc', 'services.github.client_secret' => 'segredo']);
    }

    /** O GitHub de mentira: quem autorizou e o e-mail verificado dele. */
    private function fakeGithub(string $id = '4242', string $login = 'maria-dev'): void
    {
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_token']),
            'api.github.com/user/emails' => Http::response([['email' => 'maria@example.com', 'primary' => true, 'verified' => true]]),
            'api.github.com/user' => Http::response(['id' => (int) $id, 'login' => $login, 'name' => 'Maria Dev']),
            'api.github.com/applications/*' => Http::response('', 204),
        ]);
    }

    private function linked(array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['email' => 'maria@example.com']);
        $user->forceFill(['github_id' => '4242', 'github_login' => 'maria-dev'])->save();

        return $user;
    }

    /** Aperta o botão na página `$from` e devolve o state que foi para o GitHub. */
    private function start(User $user, ?string $from = null): string
    {
        $location = $this->actingAs($user)->from($from ?? route('account.show'))
            ->post(route('account.reauth.github'))->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    private function comeBack(string $state, string $code = 'codigo')
    {
        return $this->get(route('account.reauth.github.callback', ['code' => $code, 'state' => $state]));
    }

    private function confirmed(User $user): ?string
    {
        return Reauthentication::method($user, session()->driver());
    }

    public function test_the_button_shows_only_for_an_account_with_github_linked(): void
    {
        $this->configure();

        $this->actingAs($this->linked())->get(route('account.show'))
            ->assertSee('Confirmar com o GitHub (@maria-dev)')
            ->assertSee(route('account.reauth.github'));

        $this->actingAs(User::factory()->create())->get(route('account.show'))->assertDontSee('Confirmar com o GitHub');
    }

    public function test_without_the_github_app_the_routes_do_not_exist(): void
    {
        $user = $this->linked();

        $this->actingAs($user)->get(route('account.show'))->assertDontSee('Confirmar com o GitHub');
        $this->actingAs($user)->post(route('account.reauth.github'))->assertNotFound();
        $this->actingAs($user)->get(route('account.reauth.github.callback', ['code' => 'x', 'state' => 'y']))->assertNotFound();
    }

    public function test_it_goes_to_github_by_post_with_the_reauth_callback(): void
    {
        $this->configure();
        $user = $this->linked();

        $this->actingAs($user)->get('/conta/reautenticar/github')->assertStatus(405);

        $location = $this->actingAs($user)->post(route('account.reauth.github'))->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame(url('/login/github/callback/reautenticar'), route('account.reauth.github.callback'));
        $this->assertSame(['Iv1.abc', 'read:user user:email', route('account.reauth.github.callback')], [$query['client_id'], $query['scope'], $query['redirect_uri']]);
        $this->assertSame(40, strlen($query['state']));
    }

    public function test_the_linked_github_confirms_and_nothing_else_changes(): void
    {
        $this->configure();
        $this->fakeGithub();
        $user = $this->linked(['email' => 'outro@example.com']);

        $this->comeBack($this->start($user))->assertRedirect(route('account.show'))->assertSessionHas('status');

        $this->assertSame('github', $this->confirmed($user));
        $this->assertSame(['outro@example.com', '4242', 'maria-dev'], [$user->refresh()->email, $user->github_id, $user->github_login]);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/applications/Iv1.abc/token'));
    }

    public function test_another_github_does_not_confirm(): void
    {
        $this->configure();
        $this->fakeGithub(id: '999', login: 'outra-pessoa');
        $user = $this->linked();

        $this->comeBack($this->start($user))->assertRedirect(route('account.show'))
            ->assertSessionHas('error', 'Quem autorizou foi o GitHub @outra-pessoa, que não é o ligado a esta conta. Entre no GitHub com a conta certa e tente de novo.');

        $this->assertNull($this->confirmed($user));
        $this->assertSame('4242', $user->refresh()->github_id);
    }

    public function test_an_account_without_github_is_never_linked_by_the_email(): void
    {
        $this->configure();
        $this->fakeGithub();
        // O GitHub de mentira tem e-mail verificado igual ao da conta: no login, isso ligaria a conta.
        $user = User::factory()->create(['email' => 'maria@example.com']);

        $this->actingAs($user)->from(route('account.show'))->post(route('account.reauth.github'))
            ->assertRedirect(route('account.show'))->assertSessionHas('error', 'Esta conta não tem um GitHub ligado. Confirme com a senha.');

        // Nem com um state que existisse na sessão.
        $this->withSession(['github_reauth' => ['state' => 'forjado', 'user' => $user->id, 'back' => route('account.show'), 'at' => now()->getTimestamp()]]);
        $this->actingAs($user)->get(route('account.reauth.github.callback', ['code' => 'codigo', 'state' => 'forjado']))->assertSessionHas('error');

        $this->assertNull($this->confirmed($user));
        $this->assertNull($user->refresh()->github_id);
        Http::assertNothingSent();
    }

    public function test_a_wrong_reused_late_or_cancelled_state_does_not_confirm(): void
    {
        $this->configure();
        $this->fakeGithub();
        $user = $this->linked();

        $this->start($user);
        $this->comeBack('outro-state')->assertSessionHas('error');

        // O state vale uma vez: o certo também já foi gasto.
        $this->comeBack('qualquer')->assertSessionHas('error');

        $state = $this->start($user);
        $this->travel(11)->minutes();
        $this->comeBack($state)->assertSessionHas('error', 'A confirmação pelo GitHub expirou ou não começou aqui. Tente de novo.');

        $state = $this->start($user);
        $this->get(route('account.reauth.github.callback', ['error' => 'access_denied', 'state' => $state]))->assertSessionHas('error', 'Confirmação cancelada no GitHub.');

        $this->assertNull($this->confirmed($user));
        Http::assertNothingSent();
    }

    public function test_a_flow_started_by_another_user_does_not_confirm(): void
    {
        $this->configure();
        // O GitHub devolve o da segunda conta: só o dono do state barra.
        $this->fakeGithub(id: '5555', login: 'outra-conta');
        $state = $this->start($this->linked());
        $other = User::factory()->create();
        $other->forceFill(['github_id' => '5555', 'github_login' => 'outra-conta'])->save();

        $this->actingAs($other)->get(route('account.reauth.github.callback', ['code' => 'codigo', 'state' => $state]))->assertSessionHas('error');

        $this->assertNull($this->confirmed($other));
    }

    public function test_admin_does_not_confirm_through_github(): void
    {
        $this->configure();
        $admin = $this->linked(['is_admin' => true]);

        $this->actingAs($admin)->from(route('account.show'))->post(route('account.reauth.github'))->assertSessionHas('error');
    }

    public function test_it_goes_back_to_the_page_where_it_started_but_never_to_another_site(): void
    {
        $this->configure();
        $this->fakeGithub();
        $user = $this->linked();

        $this->comeBack($this->start($user, route('alerts.notifiers')))->assertRedirect(route('alerts.notifiers'));
        $this->comeBack($this->start($user, 'https://evil.example/roubar'))->assertRedirect(route('account.show'));
    }

    public function test_visitors_cannot_use_the_reauth_callback(): void
    {
        $this->configure();

        $this->get(route('account.reauth.github.callback', ['code' => 'x', 'state' => 'y']))->assertRedirect(route('login'));
        $this->post(route('account.reauth.github'))->assertRedirect(route('login'));
    }
}
