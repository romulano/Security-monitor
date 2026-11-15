<?php

namespace Tests\Feature;

use App\Models\AlertProfile;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Alerts\ProfileIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Alerts\FakeProfileIndex;
use Tests\TestCase;

/** Admin → Usuários: nova senha (a do admin e a dos usuários) e remoção de conta. */
class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_the_page_lists_the_accounts_with_their_buttons(): void
    {
        $admin = $this->admin();
        $maria = User::factory()->create(['name' => 'Maria Souza', 'email' => 'maria@example.com']);
        $maria->forceFill(['github_id' => '1', 'github_login' => 'maria-dev'])->save();

        $this->actingAs($admin)->get(route('admin.users'))
            ->assertOk()
            ->assertSee('Gerar nova senha de admin')
            ->assertSee('Maria Souza')
            ->assertSee('maria@example.com')
            ->assertSee('GitHub @maria-dev', false)
            ->assertSee(route('admin.users.password', $maria))
            ->assertSee(route('admin.users.destroy', $maria));
    }

    public function test_only_admin_reaches_the_user_actions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->post(route('admin.users.password', $other))->assertForbidden();
        $this->actingAs($user)->delete(route('admin.users.destroy', $other))->assertForbidden();
        $this->assertModelExists($other);

        auth()->logout();
        $this->post(route('admin.users.password', $other))->assertRedirect(route('admin.login'));
        $this->delete(route('admin.users.destroy', $other))->assertRedirect(route('admin.login'));
    }

    public function test_admin_resets_a_user_password_and_the_old_one_stops_working(): void
    {
        $user = User::factory()->create(['email' => 'u@example.com']);

        $response = $this->actingAs($this->admin())->post(route('admin.users.password', $user))
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('new_password');

        $new = session('new_password');
        $this->assertSame(['name' => $user->name, 'email' => 'u@example.com', 'self' => false], collect($new)->except('password')->all());
        $this->assertSame(16, strlen($new['password']));
        $this->assertTrue(Hash::check($new['password'], $user->refresh()->password));
        $this->assertFalse(Hash::check('password', $user->password));

        $this->get(route('admin.users'))->assertSee('Nova senha de '.$user->name);

        auth()->logout();
        $this->post(route('login'), ['email' => 'u@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => 'u@example.com', 'password' => $new['password']])->assertRedirect(route('account.show'));
    }

    public function test_the_new_password_is_shown_only_once(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.users.password', User::factory()->create()));
        $password = session('new_password.password');

        $this->get(route('admin.users'))->assertSee($password);
        $this->get(route('admin.users'))->assertDontSee($password);
    }

    public function test_admin_resets_its_own_password_and_stays_logged_in(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['email' => 'adm@example.com'])->save();

        $this->actingAs($admin)->post(route('admin.users.password', $admin))
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('new_password.self', true);

        $password = session('new_password.password');
        $this->assertTrue(Hash::check($password, $admin->refresh()->password));
        $this->get(route('admin.users'))->assertOk()->assertSee('Nova senha da sua conta de administrador');

        auth()->logout();
        $this->post(route('admin.login'), ['email' => 'adm@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post(route('admin.login'), ['email' => 'adm@example.com', 'password' => $password])->assertRedirect(route('admin.dashboard'));
    }

    public function test_admin_cannot_reset_the_password_of_another_admin(): void
    {
        $other = $this->admin();
        $before = $other->password;

        $this->actingAs($this->admin())->post(route('admin.users.password', $other))->assertSessionHas('error');

        $this->assertSame($before, $other->refresh()->password);
    }

    public function test_the_reset_ends_the_other_sessions_of_the_account(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $other = User::factory()->create();

        foreach (['dele-1', 'dele-2'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        }
        DB::table('sessions')->insert(['id' => 'de-outro', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin())->post(route('admin.users.password', $user));

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'de-outro']);
    }

    public function test_ending_sessions_can_spare_the_one_that_asked(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->admin();

        foreach (['atual', 'outro-aparelho'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);
        }

        $admin->endSessions(except: 'atual');

        $this->assertSame(['atual'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_removed_user_loses_the_account_and_everything_tied_to_it(): void
    {
        $this->app->instance(ProfileIndex::class, $index = new FakeProfileIndex);
        $admin = $this->admin();
        $user = User::factory()->create(['email' => 'saiu@example.com']);
        $profile = AlertProfile::query()->create(['user_id' => $user->id, 'status' => 'active', 'company_name' => 'Acme', 'match_query' => 'x', 'brands' => [], 'min_criticity' => 'high', 'completed_at' => now()]);
        $index->stored[$profile->id] = 'x';
        $channel = NotificationChannel::forUser($user->id, 'telegram');
        $channel->forceFill(['method' => 'private', 'status' => 'active'])->save();
        // Convite do tempo do cadastro só por convite: a tabela ficou no banco, sem tela.
        $invite = DB::table('invites')->insertGetId(['code_hash' => hash('sha256', 'antigo'), 'hint' => 'antigo', 'created_by' => $admin->id, 'used_by' => $user->id, 'used_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->delete(route('admin.users.destroy', $user))
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('status');

        $this->assertModelMissing($user);
        $this->assertModelMissing($profile);
        $this->assertModelMissing($channel);
        $this->assertArrayNotHasKey($profile->id, $index->stored);

        // O convite antigo fica como histórico: usado, sem dono.
        $this->assertDatabaseHas('invites', ['id' => $invite, 'used_by' => null]);
        $this->assertNotNull(DB::table('invites')->where('id', $invite)->value('used_at'));
    }

    public function test_removed_user_cannot_get_back_in(): void
    {
        $user = User::factory()->create(['email' => 'saiu@example.com']);

        // Com a sessão aberta e depois do admin remover, a conta some.
        $this->actingAs($this->admin())->delete(route('admin.users.destroy', $user));
        auth()->logout();
        $this->flushSession();

        $this->post(route('login'), ['email' => 'saiu@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // Com o cadastro aberto, o e-mail liberado vira uma conta nova, do zero e sem o e-mail confirmado.
        $this->post(route('register'), ['name' => 'Saiu', 'email' => 'saiu@example.com', 'password' => 'senha-que-saiu', 'password_confirmation' => 'senha-que-saiu'])
            ->assertRedirect(route('verification.notice'));
        $this->assertNotSame($user->id, User::query()->where('email', 'saiu@example.com')->value('id'));
    }

    public function test_the_open_session_of_a_removed_user_is_no_longer_logged_in(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'aberta', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin())->delete(route('admin.users.destroy', $user));

        $this->assertDatabaseMissing('sessions', ['id' => 'aberta']);
    }

    public function test_admin_cannot_remove_itself_or_another_admin(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $this->actingAs($admin)->delete(route('admin.users.destroy', $admin))->assertSessionHas('error');
        $this->actingAs($admin)->delete(route('admin.users.destroy', $other))->assertSessionHas('error');

        $this->assertModelExists($admin);
        $this->assertModelExists($other);
    }

    public function test_removal_goes_through_even_if_the_cleanup_outside_fails(): void
    {
        $user = User::factory()->create();
        $profile = AlertProfile::query()->create(['user_id' => $user->id, 'status' => 'active', 'company_name' => 'Acme', 'brands' => [], 'min_criticity' => 'high', 'completed_at' => now()]);

        $this->app->instance(ProfileIndex::class, new class extends FakeProfileIndex
        {
            public function forget($profile): void
            {
                throw new \RuntimeException('Elasticsearch fora do ar');
            }
        });

        $this->actingAs($this->admin())->delete(route('admin.users.destroy', $user))->assertSessionHas('status');

        $this->assertModelMissing($user);
        $this->assertModelMissing($profile);
    }
}
