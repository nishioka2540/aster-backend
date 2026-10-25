<?php

namespace Tests\Feature;

use App\Application\Shift\CastHomePath;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LateShiftSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsubmitted_casts_can_save_and_submit_after_deadline_including_by_admin(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 00:00:00', 'Asia/Tokyo'));
        $admin = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create(['auth_type' => 1, 'user_type' => 1, 'role_type' => 1])->refresh();
        $id = DB::table('t_shift_requests')->insertGetId([
            'store_id' => \Tests\Support\StoreFixtures::storeId(), 'requested_by' => $admin->id, 'period_start' => '2026-10-12', 'period_end' => '2026-10-12',
            'submission_deadline' => '2026-10-09 23:59:59',
        ]);
        $payload = ['shift_request_id' => $id, 'days' => [
            ['work_date' => '2026-10-12', 'work' => false, 'has_reservation' => false],
        ]];
        foreach ([false, true] as $byAdmin) {
            foreach ([null, 0, 1] as $status) {
                $cast = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
                if ($status !== null) {
                    DB::table('t_shifts')->insert([
                        'shift_request_id' => $id, 'user_id' => $cast->id, 'status' => $status,
                    ]);
                }
                $this->actingAs($cast)->getJson('/api/shift-requests/accepting')->assertOk()
                    ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
                $this->assertSame('/submission', app(CastHomePath::class)->forUser($cast->id));
                $url = "/api/admin/shift-requests/$id/casts/{$cast->ulid}";
                if ($byAdmin) {
                    $this->actingAs($admin)->putJson($url, $payload)->assertOk();
                    $payload['days'][0] = ['work_date' => '2026-10-12', 'work' => true,
                        'start_time' => '20:00', 'end_time' => '00:00', 'has_reservation' => true, 'note' => '期限後の管理者編集'];
                    $this->putJson($url, $payload)->assertOk();
                    $this->getJson($url)->assertOk()->assertJsonPath('days.0.work', true)
                        ->assertJsonPath('days.0.note', '期限後の管理者編集');
                    $payload['days'][0] = ['work_date' => '2026-10-12', 'work' => false, 'has_reservation' => false];
                } else {
                    $this->postJson('/api/shifts/draft', $payload)->assertOk();
                    $this->postJson('/api/shifts', $payload)->assertOk();
                }
                $this->assertDatabaseHas('t_shifts', [
                    'shift_request_id' => $id, 'user_id' => $cast->id, 'status' => 2,
                ]);
                $this->actingAs($cast)->getJson('/api/shift-requests/accepting')->assertJsonCount(0, 'data');
                $this->assertSame('/my_shifts', app(CastHomePath::class)->forUser($cast->id));
                $this->postJson('/api/shifts', $payload)->assertUnprocessable();
                $this->postJson('/api/shifts/draft', $payload)->assertUnprocessable();
            }
        }
        DB::table('t_shift_requests')->where('id', $id)->update(['status' => 1]);
        $cast = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
        $this->actingAs($cast)->getJson('/api/shift-requests/accepting')->assertJsonCount(0, 'data');
        $this->postJson('/api/shifts/draft', $payload)->assertUnprocessable();
        $this->postJson('/api/shifts', $payload)->assertUnprocessable();
        $this->actingAs($admin)->putJson("/api/admin/shift-requests/$id/casts/{$cast->ulid}", $payload)
            ->assertUnprocessable();
        $this->travelBack();
    }
}
