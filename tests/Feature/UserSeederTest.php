<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DummyCastSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_assign_login_ids_and_preserve_them_on_rerun(): void
    {
        $this->seed(DatabaseSeeder::class);
        $staff = User::where('email', 'nishioka@example.com')->firstOrFail();
        $cast = User::where('email', 'ayana@example.com')->firstOrFail();
        $this->assertSame('0101A0001', $staff->login_id);
        $this->assertSame('0102A0001', $cast->login_id);

        $this->seed(DatabaseSeeder::class);
        $this->assertSame(2, User::count());
        $this->assertSame($staff->login_id, $staff->fresh()->login_id);
        $this->assertSame($cast->login_id, $cast->fresh()->login_id);

        $this->seed(DummyCastSeeder::class);
        $this->assertSame('0102A0002', User::where('email', 'cast01@example.com')->firstOrFail()->login_id);
        $this->assertSame('0102A0016', User::where('email', 'cast15@example.com')->firstOrFail()->login_id);
        $this->seed(DummyCastSeeder::class);
        $this->assertSame(17, User::count());

        $this->postJson('/login', ['login_id' => $staff->login_id, 'password' => 'pass'])->assertNoContent();
        $this->assertAuthenticatedAs($staff);
    }
}
