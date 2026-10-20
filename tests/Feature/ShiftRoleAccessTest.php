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
        ] as [$type, $role, $admin, $manage, $register]) {
            $user = User::factory()->create(['user_type' => $type, 'role_type' => $role, 'auth_type' => $admin]);
            $this->actingAs($user);
            $this->getJson('/api/shifts/status')->assertStatus($manage ? 200 : 403);
            $this->getJson('/api/shift-requests/defaults')->assertStatus($manage ? 200 : 403);
            $this->getJson('/api/shifts/mine')->assertStatus($register ? 200 : 403);
            $this->postJson('/api/shifts', [])->assertStatus($register ? 422 : 403);
            $this->postJson('/api/shifts/draft', [])->assertStatus($register ? 422 : 403);
            $this->postJson('/api/shift-requests', [])->assertStatus($manage ? 422 : 403);
            $this->getJson('/api/user')->assertOk()->assertJsonPath('home_path',
                $manage ? '/request_shift' : ($register ? '/my_shifts' : ($type === 1 && $role === 4 ? '/confirmed_shifts' : '/access_denied')));
        }
    }

    public function test_new_users_default_to_cast_and_cannot_mass_assign_privileges(): void
    {
        $user = User::create(['name' => 'Cast', 'user_type' => 1, 'role_type' => 1, 'auth_type' => 1]);
        $user->refresh();
        $this->assertSame(2, $user->user_type);
        $this->assertSame(3, $user->role_type);
        $this->assertSame(0, $user->auth_type);
    }

}
