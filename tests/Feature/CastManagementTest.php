<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CastManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $cast;
    private int $store;
    private int $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->manager = User::where('email', 'nishioka@example.com')->firstOrFail();
        $this->cast = User::where('email', 'ayana@example.com')->firstOrFail();
        $this->store = DB::table('t_store')->sole()->id;
        $this->membership = DB::table('t_store_users')->where('user_id', $this->cast->id)->sole()->id;
    }

    private function addCast(int $store, array $attributes = []): array
    {
        $cast = User::factory()->create($attributes);
        $access = DB::table('mst_access_roles')->where('code', 'cast')->sole()->id;
        $id = DB::table('t_store_users')->insertGetId([
            'store_id' => $store, 'user_id' => $cast->id, 'user_type' => 2, 'access_role_id' => $access,
        ]);
        return [$cast, $id];
    }

    private function otherStore(): int
    {
        return DB::table('t_store')->insertGetId([
            'ulid' => (string) Str::ulid(), 'name' => '他店舗', 'organization_id' => DB::table('t_store')->where('id', $this->store)->value('organization_id'),
        ]);
    }

    private function url(?int $id = null): string
    {
        return '/api/admin/casts/'.($id ?? $this->membership).'/employment';
    }

    private function roleUrl(?int $id = null): string
    {
        return '/api/admin/casts/'.($id ?? $this->membership).'/role';
    }

    public function test_role_options_only_include_active_cast_roles_in_selected_store(): void
    {
        $access = DB::table('mst_access_roles')->where('code', 'cast')->sole()->id;
        DB::table('mst_roles')->insert(['store_id' => $this->otherStore(), 'access_role_id' => $access, 'code' => 'other', 'name' => '他店舗役職']);
        DB::table('mst_roles')->insert(['store_id' => $this->store, 'access_role_id' => $access, 'code' => 'deleted', 'name' => '削除役職', 'deleted_at' => now()]);
        $response = $this->actingAs($this->manager)->getJson('/api/admin/casts')->assertOk()->assertJsonCount(2, 'roles');
        $this->assertEqualsCanonicalizing(['ママ', 'キャスト'], array_column($response->json('roles'), 'name'));
        $this->assertNotEmpty(collect($response->json('roles'))->firstWhere('name', 'ママ')['permissions']);
    }

    public function test_manager_can_set_and_clear_role_and_api_permissions_follow_the_change(): void
    {
        $role = DB::table('mst_roles')->where('code', 'mama')->sole()->id;
        $this->actingAs($this->manager)->patchJson($this->roleUrl(), ['role_id' => $role])->assertOk()
            ->assertJsonPath('data.role_id', $role)->assertJsonPath('data.role_name', 'ママ');
        $this->actingAs($this->cast)->getJson('/api/user')->assertOk()->assertJsonPath('active_membership.role.id', $role);
        $calendar = '/api/shifts/confirmed-calendar?from=2026-10-26&to=2026-10-30';
        $this->getJson($calendar)->assertOk();
        $this->actingAs($this->manager)->patchJson($this->roleUrl(), ['role_id' => null])->assertOk()->assertJsonPath('data.role_id', null);
        $this->actingAs($this->cast)->getJson('/api/user')->assertOk()->assertJsonPath('permissions', ['shift.submit']);
        $this->getJson($calendar)->assertForbidden();
        $this->getJson('/api/shifts/mine')->assertOk();
    }

    public function test_invalid_deleted_other_store_or_staff_roles_do_not_change_membership(): void
    {
        $access = DB::table('mst_access_roles')->where('code', 'cast')->sole()->id;
        $other = DB::table('mst_roles')->insertGetId(['store_id' => $this->otherStore(), 'access_role_id' => $access, 'code' => 'other', 'name' => '他店舗']);
        $deleted = DB::table('mst_roles')->insertGetId(['store_id' => $this->store, 'access_role_id' => $access, 'code' => 'deleted', 'name' => '削除', 'deleted_at' => now()]);
        $staff = DB::table('mst_roles')->where('code', 'manager')->sole()->id;
        $before = DB::table('t_store_users')->where('id', $this->membership)->value('role_id');
        $this->actingAs($this->manager);
        foreach ([$other, $deleted, $staff, 999999, 'invalid', [], false] as $id) {
            $this->patchJson($this->roleUrl(), ['role_id' => $id])->assertUnprocessable();
            $this->assertDatabaseHas('t_store_users', ['id' => $this->membership, 'role_id' => $before]);
        }
        $this->patchJson($this->roleUrl(), [])->assertUnprocessable();
    }

    public function test_role_update_requires_cast_management_and_rejects_cross_store_targets(): void
    {
        $this->patchJson($this->roleUrl(), ['role_id' => null])->assertUnauthorized();
        $this->actingAs($this->cast)->patchJson($this->roleUrl(), ['role_id' => null])->assertForbidden();
        [, $other] = $this->addCast($this->otherStore());
        $staff = DB::table('t_store_users')->where('user_id', $this->manager->id)->sole()->id;
        $this->actingAs($this->manager);
        foreach ([$other, $staff, 999999] as $id) $this->patchJson($this->roleUrl($id), ['role_id' => null])->assertNotFound();
        $this->withHeader('X-Store-Id', '999999')->patchJson($this->roleUrl(), ['role_id' => null])->assertStatus(409);
    }

    public function test_retired_cast_role_can_be_prepared_without_restoring_membership(): void
    {
        DB::table('t_store_users')->where('id', $this->membership)->update(['deleted_at' => now()]);
        $role = DB::table('mst_roles')->where('code', 'mama')->sole()->id;
        $this->actingAs($this->manager)->patchJson($this->roleUrl(), ['role_id' => $role])->assertOk()
            ->assertJsonPath('data.retired', true)->assertJsonPath('data.role_id', $role);
        $this->assertSoftDeleted('t_store_users', ['id' => $this->membership]);
        $this->actingAs($this->cast)->getJson('/api/shifts/mine')->assertForbidden();
    }

    public function test_system_admin_without_membership_can_assign_roles(): void
    {
        $system = User::factory()->create(['is_system_admin' => true]);
        $role = DB::table('mst_roles')->where('code', 'mama')->sole()->id;
        $this->actingAs($system)->patchJson($this->roleUrl(), ['role_id' => $role])->assertOk()->assertJsonPath('data.role_id', $role);
    }

    public function test_manager_lists_active_and_retired_casts_only_in_selected_store(): void
    {
        [$retired, $id] = $this->addCast($this->store, ['name' => '退職キャスト', 'line_id' => 'private-line']);
        DB::table('t_store_users')->where('id', $id)->update(['deleted_at' => now()]);
        [$deleted] = $this->addCast($this->store); $deleted->delete();
        $this->addCast($this->otherStore());
        $data = $this->actingAs($this->manager)->getJson('/api/admin/casts')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertEqualsCanonicalizing([$this->membership, $id], array_column($data, 'id'));
        $row = collect($data)->firstWhere('id', $id);
        $this->assertTrue($row['retired']);
        $this->assertTrue($row['line_linked']);
        foreach (['password', 'email', 'line_id'] as $key) $this->assertArrayNotHasKey($key, $row);
        $this->assertContains('cast.manage', $this->getJson('/api/user')->assertOk()->json('permissions'));
    }

    public function test_retirement_only_soft_deletes_current_store_membership_and_preserves_history(): void
    {
        $other = $this->otherStore();
        $access = DB::table('mst_access_roles')->where('code', 'cast')->sole()->id;
        $otherMembership = DB::table('t_store_users')->insertGetId([
            'store_id' => $other, 'user_id' => $this->cast->id, 'user_type' => 2, 'access_role_id' => $access,
        ]);
        $request = DB::table('t_shift_requests')->insertGetId([
            'store_id' => $this->store, 'requested_by' => $this->manager->id, 'period_start' => '2026-10-26',
            'period_end' => '2026-10-30', 'submission_deadline' => '2026-10-23 23:59:59',
        ]);
        $shift = DB::table('t_shifts')->insertGetId(['shift_request_id' => $request, 'user_id' => $this->cast->id, 'status' => 2]);
        $role = DB::table('t_store_users')->where('id', $this->membership)->value('role_id');
        $this->actingAs($this->manager)->patchJson($this->url(), ['retired' => true])->assertOk()->assertJsonPath('data.retired', true);
        $this->assertSoftDeleted('t_store_users', ['id' => $this->membership]);
        $this->assertDatabaseHas('t_store_users', ['id' => $this->membership, 'role_id' => $role]);
        $this->assertDatabaseHas('t_store_users', ['id' => $otherMembership, 'deleted_at' => null]);
        $this->assertDatabaseHas('mst_users', ['id' => $this->cast->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('t_shifts', ['id' => $shift, 'deleted_at' => null]);
        $this->actingAs($this->cast)->withSession(['active_store_id' => $this->store])->getJson('/api/shifts/mine')->assertForbidden();
    }

    public function test_restore_reuses_membership_and_restores_role_permissions(): void
    {
        $this->actingAs($this->manager)->patchJson($this->url(), ['retired' => true])->assertOk();
        $this->patchJson($this->url(), ['retired' => false])->assertOk()->assertJsonPath('data.retired', false)->assertJsonPath('data.retired_at', null);
        $this->assertDatabaseCount('t_store_users', 2);
        $this->actingAs($this->cast)->getJson('/api/user')->assertOk()->assertJsonPath('permissions', ['shift.submit']);
        $this->getJson('/api/shifts/mine')->assertOk();
    }

    public function test_repeated_retirement_keeps_original_retirement_timestamp(): void
    {
        $first = $this->actingAs($this->manager)->patchJson($this->url(), ['retired' => true])->assertOk()->json('data.retired_at');
        $this->travel(1)->hours();
        $this->patchJson($this->url(), ['retired' => true])->assertOk()->assertJsonPath('data.retired_at', $first);
    }

    public function test_staff_and_cast_cannot_manage_casts_and_system_admin_can(): void
    {
        $this->getJson('/api/admin/casts')->assertUnauthorized();
        $this->patchJson($this->url(), ['retired' => true])->assertUnauthorized();
        $this->actingAs($this->cast)->getJson('/api/admin/casts')->assertForbidden();
        $this->patchJson($this->url(), ['retired' => true])->assertForbidden();
        $staffRole = DB::table('mst_roles')->where('code', 'staff')->sole()->id;
        DB::table('t_store_users')->where('user_id', $this->manager->id)->update(['role_id' => $staffRole]);
        $this->actingAs($this->manager)->getJson('/api/admin/casts')->assertForbidden();
        $this->patchJson($this->url(), ['retired' => true])->assertForbidden();
        $system = User::factory()->create(['is_system_admin' => true]);
        $this->actingAs($system)->getJson('/api/admin/casts')->assertOk();
        $this->patchJson($this->url(), ['retired' => true])->assertOk();
    }

    public function test_other_store_staff_and_deleted_user_targets_cannot_be_updated(): void
    {
        [, $other] = $this->addCast($this->otherStore());
        [$deleted, $deletedId] = $this->addCast($this->store); $deleted->delete();
        $staff = DB::table('t_store_users')->where('user_id', $this->manager->id)->sole()->id;
        $this->actingAs($this->manager);
        foreach ([$other, $staff, $deletedId, 999999] as $id) {
            $this->patchJson($this->url($id), ['retired' => true])->assertNotFound();
            if ($id !== 999999) $this->assertDatabaseHas('t_store_users', ['id' => $id, 'deleted_at' => null]);
        }
    }

    public function test_invalid_input_and_stale_store_context_do_not_change_membership(): void
    {
        $this->actingAs($this->manager)->patchJson($this->url(), [])->assertUnprocessable();
        $this->patchJson($this->url(), ['retired' => 'invalid'])->assertUnprocessable();
        $this->withHeader('X-Store-Id', '999999')->patchJson($this->url(), ['retired' => true])->assertStatus(409);
        $this->assertDatabaseHas('t_store_users', ['id' => $this->membership, 'deleted_at' => null]);
    }
}
