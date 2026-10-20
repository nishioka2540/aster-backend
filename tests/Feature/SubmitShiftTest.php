<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubmitShiftTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        $owner = User::factory()->create()->refresh();
        $id = DB::table('t_shift_requests')->insertGetId([
            'requested_by' => $owner->id, 'period_start' => '2026-10-12',
            'period_end' => '2026-10-13', 'submission_deadline' => '2099-10-09 23:59:59',
        ]);

        return ['shift_request_id' => $id, 'days' => [
            ['work_date' => '2026-10-12', 'work' => true, 'start_time' => '20:00', 'end_time' => '00:00', 'has_reservation' => true, 'note' => 'ノンアル'],
            ['work_date' => '2026-10-13', 'work' => false, 'has_reservation' => false],
        ]];
    }

    public function test_cast_can_submit_and_resubmit_without_duplicate_rows(): void
    {
        $payload = $this->payload();
        $cast = User::factory()->create()->refresh();
        $response = $this->actingAs($cast)->postJson('/api/shifts', [...$payload, 'user_id' => 999])->assertOk();
        $this->assertDatabaseHas('t_shifts', ['id' => $response->json('id'), 'user_id' => $cast->id, 'status' => 2]);
        $this->assertDatabaseHas('t_shift_details', ['work_date' => '2026-10-12', 'ends_next_day' => true, 'has_reservation' => true, 'note' => 'ノンアル']);
        $payload['days'][0]['work'] = false;
        $this->postJson('/api/shifts', $payload)->assertOk()->assertJsonPath('id', $response->json('id'));
        $this->assertDatabaseCount('t_shifts', 1);
        $this->assertDatabaseHas('t_shifts', ['id' => $response->json('id'), 'status' => 2]);
        $this->assertDatabaseCount('t_shift_details', 2);
        $this->assertDatabaseHas('t_shift_details', ['work_date' => '2026-10-12', 'availability' => 0, 'start_time' => null, 'end_time' => null, 'note' => null, 'has_reservation' => false]);
    }

    public function test_invalid_submissions_do_not_write_any_rows(): void
    {
        $payload = $this->payload();
        $this->actingAs(User::factory()->create()->refresh());
        $invalid = $payload;
        array_pop($invalid['days']);
        $this->postJson('/api/shifts', $invalid)->assertUnprocessable();
        foreach (['19:45', '20:01', '22:15', null] as $time) {
            $invalid = $payload;
            $invalid['days'][0]['start_time'] = $time;
            $this->postJson('/api/shifts', $invalid)->assertUnprocessable();
        }
        foreach (['00:15', '21:45', null] as $time) {
            $invalid = $payload;
            $invalid['days'][0]['end_time'] = $time;
            $this->postJson('/api/shifts', $invalid)->assertUnprocessable();
        }
        $invalid = $payload;
        $invalid['days'][1]['work_date'] = '2026-10-17';
        $this->postJson('/api/shifts', $invalid)->assertUnprocessable();
        $invalid['days'][1]['work_date'] = '2026-10-12';
        $this->postJson('/api/shifts', $invalid)->assertUnprocessable();
        $this->assertDatabaseCount('t_shifts', 0);
        $this->assertDatabaseCount('t_shift_details', 0);
    }

    public function test_closed_missing_and_deleted_requests_are_rejected(): void
    {
        $payload = $this->payload();
        $this->actingAs(User::factory()->create()->refresh());
        foreach ([['status' => 1], ['deleted_at' => now()]] as $changes) {
            DB::table('t_shift_requests')->where('id', $payload['shift_request_id'])->update($changes);
            $this->postJson('/api/shifts', $payload)->assertUnprocessable();
        }
        $payload['shift_request_id'] = 999;
        $this->postJson('/api/shifts', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('t_shifts', 0);
    }

    public function test_guests_cannot_submit_and_admins_can_submit(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/shifts', $payload)->assertUnauthorized();
        $admin = User::factory()->create()->refresh();
        $admin->user_type = 1;
        $admin->role_type = 1;
        $admin->auth_type = 1;
        $admin->save();
        $this->actingAs($admin)->postJson('/api/shifts', $payload)->assertOk();
    }
}
