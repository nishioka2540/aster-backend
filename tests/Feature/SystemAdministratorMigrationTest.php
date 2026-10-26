<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\NishiyamaStoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SystemAdministratorMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_schema_defaults_to_non_system_admin_and_has_only_staff_and_cast(): void
    {
        $standard = User::factory()->create();
        $system = User::factory()->create(['is_system_admin' => true]);
        \Tests\Support\StoreFixtures::storeId();
        $this->assertFalse($standard->fresh()->is_system_admin);
        $this->assertTrue($system->fresh()->is_system_admin);
        $this->seed(NishiyamaStoreSeeder::class);
        $this->assertSame(['cast', 'staff'], DB::table('mst_access_roles')->orderBy('code')->pluck('code')->all());
        $this->actingAs($system)->getJson('/api/shifts/status')->assertOk();
    }

    public function test_legacy_numeric_system_admin_no_longer_grants_privileges(): void
    {
        $user = User::create(['name' => 'Legacy', 'auth_type' => 2, 'user_type' => 1, 'role_type' => 5]);
        $this->assertFalse($user->isSystemAdministrator());
        $this->actingAs($user)->getJson('/api/shifts/status')->assertForbidden();
    }
}
