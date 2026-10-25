<?php

namespace Tests\Feature;

use Database\Seeders\NishiyamaStoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NishiyamaStoreSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_store_roles_and_read_only_calendar_permissions_without_duplicates(): void
    {
        $this->seed(NishiyamaStoreSeeder::class);
        $store = DB::table('t_store')->where('ulid', NishiyamaStoreSeeder::STORE_ULID)->first();
        $this->assertDatabaseHas('t_organizations', ['id' => $store->organization_id, 'name' => '株式会社にし山']);
        $this->assertSame('clab にし山', $store->name);
        $roles = DB::table('mst_roles')->orderBy('sort_order')->get();
        $this->assertSame(['ママ', 'Manager', 'Staff', 'キャスト'], $roles->pluck('name')->all());
        $accessIds = DB::table('mst_access_roles')->pluck('id', 'code');
        $this->assertSame([$accessIds['cast'], $accessIds['staff'], $accessIds['staff'], $accessIds['cast']], $roles->pluck('access_role_id')->all());
        $permission = DB::table('mst_permissions')->where('code', 'shift_calendar.view')->value('id');
        foreach ($roles->where('code', '!=', 'cast') as $role) {
            $this->assertDatabaseHas('t_role_permissions', ['role_id' => $role->id, 'permission_id' => $permission]);
        }

        $this->seed(NishiyamaStoreSeeder::class);
        $this->assertDatabaseCount('t_organizations', 1);
        $this->assertDatabaseCount('t_store', 1);
        $this->assertDatabaseCount('mst_roles', 4);
        $this->assertDatabaseCount('mst_access_roles', 2);
        $this->assertDatabaseCount('mst_permissions', 4);
        $this->assertDatabaseCount('t_role_permissions', 7);
        $this->assertSame($roles->pluck('id')->all(), DB::table('mst_roles')->orderBy('sort_order')->pluck('id')->all());
    }

    public function test_rerun_preserves_store_customizations_and_does_not_restore_deleted_roles(): void
    {
        $this->seed(NishiyamaStoreSeeder::class);
        DB::table('t_store')->update(['name' => '変更後の店舗名']);
        DB::table('mst_roles')->where('code', 'staff')->update(['name' => 'スタッフ', 'deleted_at' => now()]);
        $this->seed(NishiyamaStoreSeeder::class);

        $this->assertDatabaseCount('t_store', 1);
        $this->assertDatabaseHas('t_store', ['name' => '変更後の店舗名']);
        $role = DB::table('mst_roles')->where('code', 'staff')->first();
        $this->assertSame('スタッフ', $role->name);
        $this->assertNotNull($role->deleted_at);
    }
}
