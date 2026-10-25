<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GetShiftStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_includes_missing_draft_and_deleted_shifts_per_accepting_request(): void
    {
        $admin = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
        $admin->user_type = 1;
        $admin->role_type = 1;
        $admin->auth_type = 1;
        $admin->save();
        \Tests\Support\StoreFixtures::enroll($admin);
        $casts = collect(['未登録', '未着手', '下書き', '提出済み', '削除シフト', '削除ユーザー'])
            ->map(fn ($name) => User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create(['name' => $name])->refresh());
        $casts[5]->delete();
        $request = ['store_id' => \Tests\Support\StoreFixtures::storeId(), 'requested_by' => $admin->id, 'period_start' => '2026-10-12', 'period_end' => '2026-10-13', 'submission_deadline' => '2099-10-01 23:59:59'];
        $first = DB::table('t_shift_requests')->insertGetId($request);
        $second = DB::table('t_shift_requests')->insertGetId($request);
        DB::table('t_shift_requests')->insert([...$request, 'submission_deadline' => '2000-01-01 00:00:00']);
        DB::table('t_shift_requests')->insert([...$request, 'deleted_at' => now()]);
        foreach ([1 => 0, 2 => 1, 3 => 2, 4 => 2, 5 => 2] as $index => $status) {
            DB::table('t_shifts')->insert(['shift_request_id' => $first, 'user_id' => $casts[$index]->id, 'status' => $status, 'deleted_at' => $index === 4 ? now() : null]);
        }
        $data = $this->actingAs($admin)->getJson('/api/shifts/status')->assertOk()->assertJsonCount(3, 'data')->json('data');
        $this->assertSame($first, $data[0]['id']);
        $this->assertEqualsCanonicalizing([$casts[0]->ulid, $casts[1]->ulid, $casts[2]->ulid, $casts[4]->ulid], array_column($data[0]['unsubmitted'], 'id'));
        $this->assertSame([$casts[3]->ulid], array_column($data[0]['submitted'], 'id'));
        $this->assertSame($second, $data[1]['id']);
        $this->assertCount(5, $data[1]['unsubmitted']);
        $this->assertSame([], $data[1]['submitted']);
    }

    public function test_only_admins_can_read_status(): void
    {
        $this->getJson('/api/shifts/status')->assertUnauthorized();
        $this->actingAs(User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh())->getJson('/api/shifts/status')->assertForbidden();
    }

    public function test_no_accepting_requests_returns_empty_list(): void
    {
        $admin = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
        $admin->user_type = 1;
        $admin->role_type = 1;
        $admin->auth_type = 1;
        $admin->save();
        \Tests\Support\StoreFixtures::enroll($admin);
        $this->actingAs($admin)->getJson('/api/shifts/status')->assertOk()->assertExactJson(['data' => []]);
    }
}
