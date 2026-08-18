<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_admin_cannot_update_super_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $superAdmin = User::factory()->create([
            'first_name' => 'Root',
            'last_name' => 'Admin',
            'email' => 'root@example.com',
            'role' => 'super_admin',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $superAdmin), [
                'first_name' => 'Changed',
                'last_name' => 'Admin',
                'email' => 'changed@example.com',
                'role' => 'admin',
            ])
            ->assertForbidden();

        $this->assertSame('root@example.com', $superAdmin->fresh()->email);
        $this->assertSame('super_admin', $superAdmin->fresh()->role);
    }

    public function test_regular_admin_cannot_reset_super_admin_two_factor(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.users.reset-2fa', $superAdmin))
            ->assertForbidden();

        $superAdmin->refresh();

        $this->assertNotNull($superAdmin->two_factor_secret);
        $this->assertNotNull($superAdmin->two_factor_confirmed_at);
    }

    public function test_super_admin_can_update_super_admin(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin']);
        $superAdmin = User::factory()->create([
            'first_name' => 'Root',
            'last_name' => 'Admin',
            'email' => 'root@example.com',
            'role' => 'super_admin',
        ]);

        $this->actingAs($actor)
            ->put(route('admin.users.update', $superAdmin), [
                'first_name' => 'Updated',
                'last_name' => 'Admin',
                'email' => 'updated@example.com',
                'role' => 'super_admin',
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->assertSame('updated@example.com', $superAdmin->fresh()->email);
    }
}
