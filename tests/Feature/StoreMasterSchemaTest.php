<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreMasterSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function store(): int
    {
        $organization = DB::table('t_organizations')->insertGetId([
            'ulid' => (string) Str::ulid(), 'name' => '契約組織',
        ]);

        return DB::table('t_store')->insertGetId([
            'ulid' => (string) Str::ulid(), 'organization_id' => $organization, 'name' => '店舗',
        ]);
    }

    public function test_cast_mama_can_have_calendar_permission_and_different_membership_in_another_store(): void
    {
        $store = $this->store();
        $otherStore = $this->store();
        $user = User::factory()->create();
        $role = DB::table('mst_roles')->insertGetId([
            'store_id' => $store, 'access_role_id' => DB::table('mst_access_roles')->where('code', 'cast')->value('id'), 'code' => 'mama', 'name' => 'ママ',
        ]);
        $permission = DB::table('mst_permissions')->where('code', 'shift_calendar.view')->value('id');
        DB::table('t_role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);

        DB::table('t_store_users')->insert([
            'store_id' => $store, 'user_id' => $user->id, 'user_type' => 2,
            'access_role_id' => DB::table('mst_access_roles')->where('code', 'cast')->value('id'),
            'role_id' => $role,
        ]);
        DB::table('t_store_users')->insert([
            'store_id' => $otherStore, 'user_id' => $user->id, 'user_type' => 1,
            'access_role_id' => DB::table('mst_access_roles')->where('code', 'staff')->value('id'),
        ]);

        $this->assertDatabaseCount('t_store_users', 2);
        $this->assertDatabaseHas('t_role_permissions', ['role_id' => $role, 'permission_id' => $permission]);
        $this->assertDatabaseCount('mst_permissions', 4);
    }

    public function test_role_from_another_store_cannot_be_assigned(): void
    {
        $store = $this->store();
        $role = DB::table('mst_roles')->insertGetId([
            'store_id' => $this->store(), 'access_role_id' => DB::table('mst_access_roles')->where('code', 'cast')->value('id'), 'code' => 'mama', 'name' => 'ママ',
        ]);
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('t_store_users')->insert([
            'store_id' => $store, 'user_id' => $user->id, 'user_type' => 2, 'role_id' => $role,
            'access_role_id' => DB::table('mst_access_roles')->where('code', 'cast')->value('id'),
        ]);
    }

    public function test_cast_cannot_be_assigned_staff_access(): void
    {
        $store = $this->store();
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('t_store_users')->insert([
            'store_id' => $store, 'user_id' => $user->id, 'user_type' => 2,
            'access_role_id' => DB::table('mst_access_roles')->where('code', 'staff')->value('id'),
        ]);
    }

    public function test_role_cannot_reference_nonexistent_access_role(): void
    {
        $store = $this->store();
        $this->expectException(QueryException::class);
        DB::table('mst_roles')->insert([
            'store_id' => $store, 'access_role_id' => 999999, 'code' => 'invalid', 'name' => '不正な区分',
        ]);
    }

    public function test_cast_membership_cannot_use_staff_position_in_same_store(): void
    {
        $store = $this->store();
        $user = User::factory()->create();
        $role = DB::table('mst_roles')->insertGetId([
            'store_id' => $store, 'access_role_id' => DB::table('mst_access_roles')->where('code', 'staff')->value('id'),
            'code' => 'manager', 'name' => 'Manager',
        ]);
        $this->expectException(QueryException::class);
        DB::table('t_store_users')->insert([
            'store_id' => $store, 'user_id' => $user->id, 'user_type' => 2,
            'access_role_id' => DB::table('mst_access_roles')->where('code', 'cast')->value('id'), 'role_id' => $role,
        ]);
    }

    public function test_new_tables_can_be_rolled_back_and_recreated(): void
    {
        $files = glob(database_path('migrations/2026_10_2[45]_*.php'));
        foreach (array_reverse($files) as $file) {
            (require $file)->down();
        }
        $this->assertFalse(Schema::hasTable('t_store'));
        $this->assertTrue(Schema::hasTable('mst_users'));

        foreach ($files as $file) {
            (require $file)->up();
        }
        $this->assertTrue(Schema::hasTable('t_store_users'));
        $this->assertDatabaseCount('mst_access_roles', 2);
        $this->assertDatabaseCount('mst_permissions', 4);
    }
}
