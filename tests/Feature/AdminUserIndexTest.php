<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminUserIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_index_does_not_scale_its_query_count_with_the_user_count(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        User::factory()->count(20)->create();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();

        // hasPasskeysEnabled() per row made this one query per user: 21 users
        // measured at 22 queries. A fixed ceiling is the assertion that matters
        // -- a plain "the page loads" test would not have caught it.
        $this->assertLessThan(10, $queries, "the index ran {$queries} queries for 21 users");
    }

    public function test_a_get_of_the_index_deletes_nothing(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $existing = User::factory()->create();

        // Both of these were deleted by the old index: one used, one whose
        // email already has an account.
        $used = UserInvite::create([
            'email' => 'used@example.com',
            'token' => 'token-used',
            'role' => 'user',
            'expires_at' => now()->addDay(),
            'used_at' => now()->subHour(),
        ]);

        $shadowed = UserInvite::create([
            'email' => $existing->email,
            'token' => 'token-shadowed',
            'role' => 'user',
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();

        $this->assertDatabaseHas('user_invites', ['id' => $used->id]);
        $this->assertDatabaseHas('user_invites', ['id' => $shadowed->id]);
    }

    public function test_spent_invites_are_hidden_from_the_list_and_pruned_by_the_command(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        UserInvite::create([
            'email' => 'used@example.com',
            'token' => 'token-used',
            'role' => 'user',
            'expires_at' => now()->addDay(),
            'used_at' => now()->subHour(),
        ]);

        $pending = UserInvite::create([
            'email' => 'pending@example.com',
            'token' => 'token-pending',
            'role' => 'user',
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();

        $invites = $response->viewData('page')['props']['invites']['data'];

        $this->assertCount(1, $invites);
        $this->assertSame($pending->id, $invites[0]['id']);

        $this->artisan('invites:prune')->assertSuccessful();

        $this->assertDatabaseMissing('user_invites', ['email' => 'used@example.com']);
        $this->assertDatabaseHas('user_invites', ['email' => 'pending@example.com']);
    }

    public function test_both_lists_are_paginated(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        User::factory()->count(40)->create();

        $response = $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();

        $users = $response->viewData('page')['props']['users'];

        $this->assertCount(25, $users['data'], 'the user list is not paginated');
        $this->assertSame(41, $users['total']);
        $this->assertSame(2, $users['last_page']);
    }

    public function test_the_passkey_flag_survives_the_switch_to_a_counted_column(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();

        $row = collect($response->viewData('page')['props']['users']['data'])
            ->firstWhere('id', $admin->id);

        $this->assertFalse($row['two_factor']['passkeys_enabled']);
    }
}
