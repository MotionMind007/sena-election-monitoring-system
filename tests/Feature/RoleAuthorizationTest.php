<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_user_is_limited_to_one_of_the_three_roles(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = User::factory()->role($role)->create();

            $this->assertSame($role, $user->role);
        }
    }

    public function test_only_super_admin_can_access_admin_route(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $coordinator = User::factory()->role(UserRole::Coordinator)->create();
        $witness = User::factory()->role(UserRole::Witness)->create();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($coordinator)->get('/admin')->assertForbidden();
        $this->actingAs($witness)->get('/admin')->assertForbidden();
    }

    public function test_system_management_gate_is_restricted_to_super_admin(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $coordinator = User::factory()->role(UserRole::Coordinator)->create();

        $this->assertTrue(Gate::forUser($admin)->allows('manage-system'));
        $this->assertFalse(Gate::forUser($coordinator)->allows('manage-system'));
    }
}
