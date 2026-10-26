<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_matrix_controls_management_and_cast_apis(): void
    {
        foreach ([
            [1, 1, 0, true, false],
            [1, 2, 0, true, false],
            [1, 3, 0, false, false],
            [1, 4, 0, false, false],
            [1, 5, 0, false, false],
            [2, 1, 0, false, true],
            [2, 2, 0, false, true],
            [2, 3, 0, false, true],
            [1, 5, 1, true, true],
            [2, 3, 1, true, true],
            [1, 5, 2, true, true],
            [2, 3, 2, true, true],
        ] as [$type, $role, $admin, $manage, $register]) {
            $user = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member, $type, $role, $admin === 2 ? 0 : $admin))->create(['is_system_admin' => $admin === 2]);
            $this->actingAs($user);
            $this->getJson('/api/shifts/status')->assertStatus($manage ? 200 : 403);
            $this->getJson('/api/shift-requests/defaults')->assertStatus($manage ? 200 : 403);
            $this->getJson('/api/shifts/mine')->assertStatus($register ? 200 : 403);
            $this->postJson('/api/shifts', [])->assertStatus($register ? 422 : 403);
            $this->postJson('/api/shifts/draft', [])->assertStatus($register ? 422 : 403);
            $this->postJson('/api/shift-requests', [])->assertStatus($manage ? 422 : 403);
            $this->getJson('/api/user')->assertOk()->assertJsonPath('home_path',
                $manage ? '/request_shift' : ($register ? '/my_shifts' : ($type === 1 ? '/confirmed_shifts' : '/access_denied')));
        }
    }

    public function test_new_users_cannot_mass_assign_removed_privileges(): void
    {
        $user = User::create(['name' => 'Cast', 'user_type' => 1, 'role_type' => 1, 'auth_type' => 1]);
        $user->refresh();
        $this->assertArrayNotHasKey('user_type', $user->getAttributes());
        $this->assertArrayNotHasKey('role_type', $user->getAttributes());
        $this->assertArrayNotHasKey('auth_type', $user->getAttributes());
    }

    public function test_system_administrator_is_distinct_and_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member, 2, 3, 0))->create(['is_system_admin' => true]);
        $this->assertTrue($user->fresh()->isSystemAdministrator());
        $this->assertTrue($user->fresh()->isAdministrator());
        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('is_system_admin', true);

        $admin = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member, 2, 3, 1))->create([]);
        $this->assertFalse($admin->isAdministrator());
        $this->assertFalse($admin->isSystemAdministrator());

        $standard = User::create(['name' => 'Standard', 'is_system_admin' => true]);
        $this->assertFalse($standard->fresh()->is_system_admin);
        $this->assertFalse($standard->fresh()->isAdministrator());
    }

}
