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
