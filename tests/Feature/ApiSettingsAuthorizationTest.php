<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiSettingsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_cannot_change_the_registrar_credentials(): void
    {
        Setting::set('realtime_register_host', 'is.yoursrs.com');

        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->put(route('settings.api'), ['host' => 'attacker.example.com'])
            ->assertForbidden();

        $this->assertSame('is.yoursrs.com', Setting::get('realtime_register_host'));
    }

    public function test_admin_can_change_the_registrar_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->put(route('settings.api'), ['host' => 'is.yoursrs.com'])
            ->assertRedirect();

        $this->assertSame('is.yoursrs.com', Setting::get('realtime_register_host'));
    }
}
