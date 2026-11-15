<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ninguém vira admin por atribuição em massa: só o seed e o código que decide (forceFill/forceCreate). */
class UserMassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_admin_is_ignored_when_a_user_is_created_with_an_array(): void
    {
        $user = User::create([
            'name' => 'Maria',
            'email' => 'maria@example.com',
            'password' => 'segredo-longo-1',
            'is_admin' => true,
        ]);

        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_is_admin_is_ignored_when_a_user_is_updated_with_an_array(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $user->update(['name' => 'Outro nome', 'is_admin' => true]);

        $this->assertSame('Outro nome', $user->fresh()->name);
        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_the_code_that_decides_can_still_promote(): void
    {
        $user = User::factory()->create();

        $user->forceFill(['is_admin' => true])->save();

        $this->assertTrue($user->fresh()->is_admin);
    }
}
