<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpdateShiftRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_all_fields_and_period_changes_require_resubmission_and_replanning(): void
    {
        $admin = User::factory()->create(['auth_type' => 1, 'user_type' => 1, 'role_type' => 1])->refresh();
        $cast = User::factory()->create()->refresh();
        $payload = ['period_start' => '2026-10-19', 'period_end' => '2026-10-23', 'submission_deadline' => '2026-10-16 23:59:59', 'notice' => 'Original'];
        $id = DB::table('t_shift_requests')->insertGetId([...$payload, 'requested_by' => $admin->id]);
        $shift = DB::table('t_shifts')->insertGetId(['shift_request_id' => $id, 'user_id' => $cast->id, 'status' => 2, 'submitted_at' => now()]);
        DB::table('t_shift_details')->insert(['shift_id' => $shift, 'work_date' => '2026-10-19', 'availability' => 0]);
        $day = DB::table('t_shift_schedule_days')->insertGetId(['shift_request_id' => $id, 'work_date' => '2026-10-19', 'status' => 2, 'confirmed_by' => $admin->id]);
        DB::table('t_shift_assignments')->insert(['shift_schedule_day_id' => $day, 'user_id' => $cast->id, 'work' => 0]);
        $url = "/api/shift-requests/$id";
        $this->putJson($url, $payload)->assertUnauthorized();
        $this->actingAs($cast)->putJson($url, $payload)->assertForbidden();
        $this->actingAs($admin);
        $payload['notice'] = 'Changed';
        $payload['submission_deadline'] = '2026-10-19 23:59:59';
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseHas('t_shifts', ['id' => $shift, 'status' => 2]);
        $this->assertDatabaseHas('t_shift_schedule_days', ['id' => $day, 'status' => 2, 'deleted_at' => null]);
        $payload['period_start'] = '2026-10-20';
        $payload['period_end'] = '2026-10-26';
        $this->putJson($url, $payload)->assertOk();
        $this->assertDatabaseHas('t_shift_requests', ['id' => $id, ...$payload, 'requested_by' => $admin->id]);
        $this->assertDatabaseHas('t_shifts', ['id' => $shift, 'status' => 1, 'submitted_at' => null]);
        $this->assertDatabaseHas('t_shift_details', ['shift_id' => $shift, 'deleted_at' => null]);
        $this->assertSoftDeleted('t_shift_schedule_days', ['id' => $day]);
        $this->assertSoftDeleted('t_shift_assignments', ['shift_schedule_day_id' => $day]);
        $this->putJson($url, [...$payload, 'period_end' => '2026-10-19'])->assertUnprocessable();
        $this->assertDatabaseHas('t_shift_requests', ['id' => $id, 'period_end' => '2026-10-26']);
        DB::table('t_shift_requests')->where('id', $id)->update(['status' => 1]);
        $this->putJson($url, $payload)->assertUnprocessable();
        DB::table('t_shift_requests')->where('id', $id)->update(['deleted_at' => now()]);
        $this->putJson($url, $payload)->assertNotFound();
        $this->putJson('/api/shift-requests/999', $payload)->assertNotFound();
    }
}
