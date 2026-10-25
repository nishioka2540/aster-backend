<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminCastShiftTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_submit_for_a_cast_without_a_draft(): void
    {
        $admin = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create(['auth_type' => 1, 'user_type' => 1, 'role_type' => 1])->refresh();
        $cast = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
        $id = DB::table('t_shift_requests')->insertGetId([
            'store_id' => \Tests\Support\StoreFixtures::storeId(), 'requested_by' => $admin->id, 'period_start' => '2026-11-02', 'period_end' => '2026-11-02',
            'submission_deadline' => '2099-10-09 23:59:59',
        ]);
        $url = "/api/admin/shift-requests/$id/casts/{$cast->ulid}";
        $this->actingAs($admin)->putJson($url, [
            'shift_request_id' => $id,
            'days' => [['work_date' => '2026-11-02', 'work' => true, 'start_time' => '20:00',
                'end_time' => '00:00', 'has_reservation' => false, 'note' => '代理入力']],
        ])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('submitted', true)
            ->assertJsonPath('days.0.note', '代理入力');
        $this->assertDatabaseHas('t_shifts', ['shift_request_id' => $id, 'user_id' => $cast->id, 'status' => 2]);
    }

    public function test_admin_can_read_register_and_edit_a_cast_submission(): void
    {
        $admin = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
        $admin->user_type = 1;
        $admin->role_type = 1;
        $admin->auth_type = 1;
        $admin->save();
        \Tests\Support\StoreFixtures::enroll($admin);
        $cast = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
        $id = DB::table('t_shift_requests')->insertGetId([
            'store_id' => \Tests\Support\StoreFixtures::storeId(), 'requested_by' => $admin->id, 'period_start' => '2026-10-12', 'period_end' => '2026-10-12',
            'submission_deadline' => '2099-10-09 23:59:59',
        ]);
        $url = "/api/admin/shift-requests/$id/casts/{$cast->ulid}";
        $payload = ['shift_request_id' => $id, 'days' => [[
            'work_date' => '2026-10-12', 'work' => true, 'start_time' => '20:00',
            'end_time' => '00:00', 'has_reservation' => true, 'note' => 'ノンアル',
        ]]];
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($cast)->getJson($url)->assertForbidden();
        $this->putJson($url, $payload)->assertForbidden();
        $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('submitted', false)->assertJsonPath('days', []);
        $this->getJson("/api/admin/shift-requests/$id/casts/{$cast->id}")->assertNotFound();
        $this->putJson("/api/admin/shift-requests/$id/casts/{$cast->id}", $payload)->assertNotFound();
        $this->actingAs($cast)->postJson('/api/shifts/draft', $payload)->assertOk();
        $this->getJson('/api/shifts/mine')->assertOk()->assertJsonPath('data.0.days.0.note', 'ノンアル');
        $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('submitted', false)->assertJsonPath('days', []);
        $this->assertDatabaseHas('t_shifts', ['user_id' => $cast->id, 'status' => 1]);
        $this->putJson($url, $payload)->assertOk();
        $this->actingAs($admin);
        $this->getJson($url)->assertOk()->assertJsonPath('submitted', true)
            ->assertJsonPath('days.0.note', 'ノンアル')->assertJsonPath('days.0.end_time', '00:00');
        $this->getJson('/api/shifts/status')->assertOk()->assertJsonPath('data.0.submitted.0.id', $cast->ulid);
        $payload['days'][0]['work'] = false;
        $this->getJson("/api/admin/shift-requests/$id/casts/{$cast->id}")->assertNotFound();
        $this->putJson("/api/admin/shift-requests/$id/casts/{$cast->id}", $payload)->assertNotFound();
        $this->putJson($url, $payload)->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('days.0.work', false)->assertJsonPath('days.0.start_time', null);
        $this->assertDatabaseCount('t_shifts', 1);
        $this->assertDatabaseHas('t_shifts', ['user_id' => $cast->id]);
        $payload['days'][0]['work'] = true;
        $payload['days'][0]['start_time'] = '19:00';
        $this->putJson($url, $payload)->assertUnprocessable();
        $this->getJson($url)->assertJsonPath('days.0.work', false);
        DB::table('t_shift_requests')->where('id', $id)->update(['submission_deadline' => '2000-01-01 00:00:00']);
        $this->putJson($url, $payload)->assertUnprocessable();
        $this->getJson($url)->assertOk();
        $this->getJson("/api/admin/shift-requests/$id/casts/{$admin->ulid}")->assertNotFound();
        $cast->delete();
        $this->putJson($url, $payload)->assertNotFound();
    }
}
