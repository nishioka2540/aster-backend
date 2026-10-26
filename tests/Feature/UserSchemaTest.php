<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_have_nullable_credentials_without_legacy_columns(): void
    {
        $user = User::create(['name' => 'Cast']);

        $user->refresh();

        $this->assertNull($user->email);
        $this->assertNull($user->password);
        foreach (['auth_type', 'user_type', 'role_type'] as $column) {
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('mst_users', $column));
        }
    }

    public function test_ulids_are_unique_and_stable_without_changing_internal_ids(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $ulid = $first->ulid;
        $this->assertTrue(\Illuminate\Support\Str::isUlid($ulid));
        $this->assertNotSame($ulid, $second->ulid);
        $this->assertIsInt($first->id);
        $first->update(['name' => 'Changed']);
        $this->assertSame($ulid, $first->fresh()->ulid);
    }

    public function test_migration_backfills_existing_and_deleted_users(): void
    {
        $migration = require database_path('migrations/2026_10_16_000000_add_ulid_to_mst_users.php');
        $migration->down();
        $id = \Illuminate\Support\Facades\DB::table('mst_users')->insertGetId(['login_id' => '0102Z9998', 'name' => 'Existing']);
        $deletedId = \Illuminate\Support\Facades\DB::table('mst_users')->insertGetId(['login_id' => '0102Z9999', 'name' => 'Deleted', 'deleted_at' => now()]);
        $migration->up();
        $first = User::findOrFail($id);
        $deleted = User::withTrashed()->findOrFail($deletedId);
        $this->assertTrue(\Illuminate\Support\Str::isUlid($first->ulid));
        $this->assertTrue(\Illuminate\Support\Str::isUlid($deleted->ulid));
        $this->assertNotSame($first->ulid, $deleted->ulid);
    }

    public function test_login_ids_use_actual_user_id_and_access_role_and_remain_stable(): void
    {
        $staff = User::factory()->create();
        $cast = User::factory()->create();
        $this->assertNull($cast->login_id);
        \Tests\Support\StoreFixtures::enroll($staff, 1);
        \Tests\Support\StoreFixtures::enroll($cast);
        $this->assertSame('0102A0001', $staff->login_id);
        $this->assertSame('0101A0002', $cast->login_id);
        $cast->delete();
        $next = User::factory()->create();
        \Tests\Support\StoreFixtures::enroll($next);
        $this->assertSame('0101A0003', $next->login_id);
        $next->update(['name' => 'Changed']);
        $this->assertSame('0101A0003', $next->fresh()->login_id);
    }

    public function test_login_id_uses_minimum_four_digits_without_changing_letter_after_9999(): void
    {
        $user = User::factory()->create(['id' => 10000]);
        \Tests\Support\StoreFixtures::enroll($user);
        $this->assertSame('0101A10000', $user->login_id);
        $this->postJson('/login', ['login_id' => $user->login_id, 'password' => 'password'])->assertNoContent();
    }

    public function test_deleted_user_is_excluded_and_can_be_restored(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->assertSoftDeleted('mst_users', ['id' => $user->id]);
        $this->assertNull(User::find($user->id));

        $user->restore();

        $this->assertNotNull(User::find($user->id));
        $this->assertNull($user->deleted_at);
    }

    public function test_login_id_uses_real_store_and_access_role_ids_and_stays_stable_across_stores(): void
    {
        $db = \Illuminate\Support\Facades\DB::class;
        $db::table('mst_access_roles')->where('code', 'cast')->update(['id' => 17]);
        $org = $db::table('t_organizations')->insertGetId(['ulid' => (string) \Illuminate\Support\Str::ulid(), 'name' => '組織']);
        $db::table('t_store')->insert(['id' => 42, 'ulid' => (string) \Illuminate\Support\Str::ulid(), 'organization_id' => $org, 'name' => 'テスト店舗']);
        $user = User::factory()->create(['id' => 23]);
        \Tests\Support\StoreFixtures::enroll($user);
        $this->assertSame('4217A0023', $user->login_id);
        $other = $db::table('t_store')->insertGetId(['ulid' => (string) \Illuminate\Support\Str::ulid(), 'organization_id' => $org, 'name' => '他店舗']);
        $db::table('t_store_users')->insert(['store_id' => $other, 'user_id' => $user->id, 'user_type' => 2, 'access_role_id' => 17]);
        $this->assertSame('4217A0023', app(\App\Services\LoginIdGenerator::class)->assign($user, $other, 17));
        $this->postJson('/login', ['login_id' => $user->login_id, 'password' => 'password'])->assertNoContent();
    }

    public function test_existing_login_id_is_kept_and_remains_usable(): void
    {
        $user = User::factory()->create(['login_id' => '0102B0001']);
        \Tests\Support\StoreFixtures::enroll($user);
        $this->assertSame('0102B0001', $user->fresh()->login_id);
        $this->postJson('/login', ['login_id' => $user->login_id, 'password' => 'password'])->assertNoContent();
    }
}
