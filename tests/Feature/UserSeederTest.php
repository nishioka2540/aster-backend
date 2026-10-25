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
        $this->assertSame('0102A0001', $staff->login_id);
        $this->assertSame('0101A0002', $cast->login_id);

        $this->seed(DatabaseSeeder::class);
        $this->assertSame(2, User::count());
        $this->assertDatabaseCount('t_store_users', 2);
        $storeId = \Illuminate\Support\Facades\DB::table('t_store')->value('id');
        $this->assertDatabaseHas('t_store_users', [
            'store_id' => $storeId, 'user_id' => $staff->id, 'user_type' => 1,
            'access_role_id' => \Illuminate\Support\Facades\DB::table('mst_access_roles')->where('code', 'staff')->value('id'),
            'role_id' => \Illuminate\Support\Facades\DB::table('mst_roles')->where('code', 'manager')->value('id'),
        ]);
        $this->assertDatabaseHas('t_store_users', [
            'store_id' => $storeId, 'user_id' => $cast->id, 'user_type' => 2,
            'access_role_id' => \Illuminate\Support\Facades\DB::table('mst_access_roles')->where('code', 'cast')->value('id'),
            'role_id' => \Illuminate\Support\Facades\DB::table('mst_roles')->where('code', 'cast')->value('id'),
        ]);
        $this->assertSame($staff->login_id, $staff->fresh()->login_id);
        $this->assertSame($cast->login_id, $cast->fresh()->login_id);

        $this->seed(DummyCastSeeder::class);
        $this->assertSame('0101A0003', User::where('email', 'cast01@example.com')->firstOrFail()->login_id);
        $this->assertSame('0101A0017', User::where('email', 'cast15@example.com')->firstOrFail()->login_id);
        $this->seed(DummyCastSeeder::class);
        $this->assertSame(17, User::count());

        $this->postJson('/login', ['login_id' => $staff->login_id, 'password' => 'pass'])->assertNoContent();
        $this->assertAuthenticatedAs($staff);
    }
}
