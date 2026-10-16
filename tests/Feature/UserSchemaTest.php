<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_have_nullable_credentials_and_defaults_to_cast_role(): void
    {
        $user = User::create(['name' => 'Cast']);

        $user->refresh();

        $this->assertNull($user->email);
        $this->assertNull($user->password);
        $this->assertSame(0, $user->auth_type);

        $user->auth_type = 1;
        $user->save();

        $this->assertSame(1, $user->fresh()->auth_type);
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
        $id = \Illuminate\Support\Facades\DB::table('mst_users')->insertGetId(['name' => 'Existing']);
        $deletedId = \Illuminate\Support\Facades\DB::table('mst_users')->insertGetId(['name' => 'Deleted', 'deleted_at' => now()]);
        $migration->up();
        $first = User::findOrFail($id);
        $deleted = User::withTrashed()->findOrFail($deletedId);
        $this->assertTrue(\Illuminate\Support\Str::isUlid($first->ulid));
        $this->assertTrue(\Illuminate\Support\Str::isUlid($deleted->ulid));
        $this->assertNotSame($first->ulid, $deleted->ulid);
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
}
