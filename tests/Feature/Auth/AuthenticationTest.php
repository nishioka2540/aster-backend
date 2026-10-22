<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'login_id' => $user->login_id,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertNoContent();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'login_id' => $user->login_id,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_email_cannot_be_used_instead_of_login_id(): void
    {
        $user = User::factory()->create();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('login_id');
        $this->assertGuest();
    }

    public function test_deleted_user_cannot_log_in(): void
    {
        $user = User::factory()->create();
        $user->delete();
        $this->postJson('/login', ['login_id' => $user->login_id, 'password' => 'password'])
            ->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertNoContent();
    }
}
