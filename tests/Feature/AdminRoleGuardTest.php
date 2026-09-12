<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRoleGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_cannot_change_their_own_role(): void
    {
        User::factory()->create(['role' => 'super_admin']);
        $actor = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($actor)
            ->put(route('admin.users.update', $actor), [
                'first_name' => $actor->first_name ?? 'Test',
                'last_name' => $actor->last_name ?? 'User',
                'email' => $actor->email,
                'role' => 'user',
            ])
            ->assertSessionHas('error');

        $this->assertSame('super_admin', $actor->fresh()->role);
    }

    public function test_the_last_super_admin_cannot_be_demoted(): void
    {
        $only = User::factory()->create(['role' => 'super_admin']);
        $actor = User::factory()->create(['role' => 'super_admin']);
        $actor->delete(); // leaves exactly one super admin, acting as another admin
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $only), [
                'first_name' => 'Only',
                'last_name' => 'Super',
                'email' => $only->email,
                'role' => 'admin',
            ]);

        $this->assertSame('super_admin', $only->fresh()->role);
    }
}
