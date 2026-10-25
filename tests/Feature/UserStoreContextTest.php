<?php

namespace Tests\Feature;

use App\Jobs\SendLineMessage;
use App\Models\User;
use App\Services\StoreAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserStoreContextTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $name): int
    {
        $org = DB::table('t_organizations')->insertGetId(['ulid' => (string) Str::ulid(), 'name' => $name.'組織']);
        return DB::table('t_store')->insertGetId(['ulid' => (string) Str::ulid(), 'organization_id' => $org, 'name' => $name]);
    }

    private function member(User $user, int $store, string $access, array $permissions): int
    {
        $accessId = DB::table('mst_access_roles')->where('code', $access)->sole()->id;
        $role = DB::table('mst_roles')->insertGetId([
            'store_id' => $store, 'access_role_id' => $accessId, 'code' => (string) Str::ulid(), 'name' => '任意の役職名',
        ]);
        foreach ($permissions as $permission) DB::table('t_role_permissions')->insert([
            'role_id' => $role, 'permission_id' => DB::table('mst_permissions')->where('code', $permission)->sole()->id,
        ]);
        DB::table('t_store_users')->insert([
            'user_id' => $user->id, 'store_id' => $store, 'user_type' => $access === 'cast' ? 2 : 1,
            'access_role_id' => $accessId, 'role_id' => $role,
        ]);
        return $role;
    }

    private function period(User $user, ?int $store): int
    {
        return DB::table('t_shift_requests')->insertGetId([
            'store_id' => $store, 'requested_by' => $user->id, 'period_start' => '2026-10-26',
            'period_end' => '2026-10-30', 'submission_deadline' => '2099-10-23 23:59:59', 'status' => 0,
        ]);
    }

    public function test_context_returns_memberships_and_only_explicit_public_user_fields(): void
    {
        $store = $this->store('店舗A');
        $user = User::factory()->create(['line_id' => 'private-line-id']);
        $role = $this->member($user, $store, 'cast', ['shift.submit', 'shift_calendar.view']);
        $json = $this->actingAs($user)->getJson('/api/user')->assertOk()
            ->assertJsonPath('memberships.0.store.id', $store)
            ->assertJsonPath('memberships.0.access_role.code', 'cast')
            ->assertJsonPath('memberships.0.role.id', $role)
            ->assertJsonPath('memberships.0.role.name', '任意の役職名')
            ->assertJsonPath('active_store.id', $store)->assertJsonPath('line_linked', true)->json();
        $this->assertEqualsCanonicalizing(['shift.submit', 'shift_calendar.view'], $json['permissions']);
        foreach (['password', 'remember_token', 'line_id', 'auth_type', 'role_type', 'user_type'] as $field) $this->assertArrayNotHasKey($field, $json);
    }

    public function test_store_selection_changes_permissions_and_rejects_nonmember_store(): void
    {
        $a = $this->store('A'); $b = $this->store('B'); $outside = $this->store('外部');
        $user = User::factory()->create();
        $this->member($user, $a, 'staff', ['shift.manage']);
        $this->member($user, $b, 'cast', ['shift.submit']);
        $this->actingAs($user)->getJson('/api/shifts/status')->assertOk();
        $this->postJson('/api/user/active-store', ['store_id' => $outside])->assertForbidden();
        $this->postJson('/api/user/active-store', ['store_id' => $b])->assertOk()
            ->assertJsonPath('active_store.id', $b)->assertJsonPath('permissions', ['shift.submit']);
        $this->getJson('/api/shifts/status')->assertForbidden();
        $this->getJson('/api/shifts/mine')->assertOk();
        $this->withHeader('X-Store-Id', (string) $a)->getJson('/api/shifts/mine')->assertStatus(409);
    }

    public function test_legacy_flags_do_not_grant_access_without_membership(): void
    {
        $this->store('A');
        $user = User::factory()->create(['auth_type' => 1, 'user_type' => 1, 'role_type' => 1]);
        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('memberships', [])
            ->assertJsonPath('permissions', [])->assertJsonPath('home_path', '/mypage');
        $this->getJson('/api/shifts/status')->assertForbidden();
        $this->postJson('/api/user/active-store', ['store_id' => 1])->assertForbidden();
    }

    public function test_system_admin_can_select_live_stores_without_membership(): void
    {
        $store = $this->store('A');
        $user = User::factory()->create(['is_system_admin' => true]);
        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('active_store.id', $store)
            ->assertJsonPath('permissions', StoreAccess::PERMISSIONS);
        $this->getJson('/api/shifts/status')->assertOk();
        DB::table('t_store')->where('id', $store)->update(['deleted_at' => now()]);
        $this->getJson('/api/shifts/status')->assertForbidden();
    }

    public function test_deleted_role_removes_permissions_but_preserves_membership(): void
    {
        $store = $this->store('A'); $user = User::factory()->create();
        $role = $this->member($user, $store, 'staff', ['shift.manage']);
        $this->actingAs($user)->getJson('/api/shifts/status')->assertOk();
        DB::table('mst_roles')->where('id', $role)->update(['deleted_at' => now()]);
        $this->getJson('/api/user')->assertOk()->assertJsonCount(1, 'memberships')->assertJsonPath('permissions', [])
            ->assertJsonPath('active_membership.role', null);
        $this->getJson('/api/shifts/status')->assertForbidden();
    }

    public function test_deleted_membership_store_and_organization_are_excluded(): void
    {
        $user = User::factory()->create();
        $stores = [$this->store('退店'), $this->store('削除店舗'), $this->store('削除組織')];
        foreach ($stores as $store) $this->member($user, $store, 'staff', ['shift.manage']);
        DB::table('t_store_users')->where('store_id', $stores[0])->update(['deleted_at' => now()]);
        DB::table('t_store')->where('id', $stores[1])->update(['deleted_at' => now()]);
        DB::table('t_organizations')->where('id', DB::table('t_store')->where('id', $stores[2])->value('organization_id'))->update(['deleted_at' => now()]);
        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('memberships', [])->assertJsonPath('stores', []);
    }

    public function test_revoked_active_store_does_not_fall_back_to_another_store(): void
    {
        $a = $this->store('A'); $b = $this->store('B'); $user = User::factory()->create();
        foreach ([$a, $b] as $store) $this->member($user, $store, 'staff', ['shift.manage']);
        $this->actingAs($user)->postJson('/api/user/active-store', ['store_id' => $b])->assertOk();
        DB::table('t_store_users')->where('store_id', $b)->update(['deleted_at' => now()]);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('active_store', null)->assertJsonPath('permissions', []);
        $this->getJson('/api/shifts/status')->assertForbidden();
    }

    public function test_period_lists_and_direct_id_operations_are_scoped_to_selected_store(): void
    {
        $a = $this->store('A'); $b = $this->store('B'); $user = User::factory()->create();
        $this->member($user, $a, 'staff', ['shift.manage']);
        $own = $this->period($user, $a); $other = $this->period($user, $b); $legacy = $this->period($user, null);
        $this->actingAs($user)->getJson('/api/shift-requests/accepting')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);
        foreach ([$other, $legacy] as $id) {
            $this->getJson("/api/admin/shift-requests/$id/schedule")->assertNotFound();
            $this->putJson("/api/shift-requests/$id", [])->assertNotFound();
            $this->postJson("/api/admin/shift-requests/$id/remind")->assertNotFound();
        }
    }

    public function test_submit_and_saved_shifts_cannot_cross_store_boundaries(): void
    {
        $a = $this->store('A'); $b = $this->store('B'); $user = User::factory()->create();
        $this->member($user, $a, 'cast', ['shift.submit']);
        $own = $this->period($user, $a); $other = $this->period($user, $b);
        foreach ([$own, $other] as $id) DB::table('t_shifts')->insert(['shift_request_id' => $id, 'user_id' => $user->id, 'status' => 1]);
        $this->actingAs($user)->getJson('/api/shifts/mine')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.shift_request_id', $own);
        $this->postJson('/api/shifts', ['shift_request_id' => $other, 'days' => []])->assertNotFound();
    }

    public function test_management_targets_and_line_notifications_only_include_current_store_casts(): void
    {
        $a = $this->store('A'); $b = $this->store('B'); $manager = User::factory()->create();
        $this->member($manager, $a, 'staff', ['shift.manage']);
        $own = User::factory()->create(['line_id' => 'own']); $other = User::factory()->create(['line_id' => 'other']);
        $this->member($own, $a, 'cast', ['shift.submit']); $this->member($other, $b, 'cast', ['shift.submit']);
        Queue::fake(); config(['services.line.messaging_token' => 'test-token']);
        $id = $this->actingAs($manager)->postJson('/api/shift-requests', [
            'period_start' => '2026-10-26', 'period_end' => '2026-10-30', 'submission_deadline' => '2026-10-23 23:59:59',
        ])->assertCreated()->json('id');
        $this->assertDatabaseHas('t_shift_requests', ['id' => $id, 'store_id' => $a]);
        Queue::assertPushed(SendLineMessage::class, 1);
        Queue::assertPushed(SendLineMessage::class, fn ($job) => $job->userId === $own->id && $job->storeId === $a);
        $this->getJson("/api/admin/shift-requests/$id/casts/$other->ulid")->assertNotFound();
        $this->getJson('/api/shifts/status')->assertOk()->assertJsonCount(1, 'data.0.unsubmitted')->assertJsonPath('data.0.unsubmitted.0.id', $own->ulid);
        Http::fake();
        DB::table('t_store_users')->where('user_id', $own->id)->update(['deleted_at' => now()]);
        (new SendLineMessage($own->id, '通知', (string) Str::uuid(), $a))->handle();
        Http::assertNothingSent();
    }
}
