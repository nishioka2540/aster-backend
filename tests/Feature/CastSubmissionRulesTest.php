<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CastSubmissionRulesTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $cast = User::factory()->create()->refresh();
        $id = DB::table('t_shift_requests')->insertGetId([
            'requested_by' => $cast->id, 'period_start' => '2026-10-12', 'period_end' => '2026-10-13',
            'submission_deadline' => '2099-10-09 23:59:59',
        ]);
        $days = array_map(fn ($date) => ['work_date' => $date, 'work' => true, 'start_time' => '20:00',
            'end_time' => '00:00', 'has_reservation' => false, 'note' => '保持する'], ['2026-10-12', '2026-10-13']);
        $this->actingAs($cast);
        return [$cast, $id, ['shift_request_id' => $id, 'days' => $days]];
    }

    public function test_finalized_days_are_rejected_and_omitted_days_are_preserved_on_resubmission(): void
    {
        [$cast, $id, $payload] = $this->fixture();
        $shiftId = $this->postJson('/api/shifts', $payload)->assertOk()->json('id');
        $original = (array) DB::table('t_shift_details')->where('shift_id', $shiftId)->where('work_date', '2026-10-12')->first();
        DB::table('t_shift_schedule_days')->insert(['shift_request_id' => $id, 'work_date' => '2026-10-12', 'status' => 2]);
        $this->getJson('/api/shift-requests/accepting')->assertJsonPath('data.0.finalized_dates', ['2026-10-12']);
        // Even a stale screen submitting unchanged finalized days is rejected atomically.
        $this->postJson('/api/shifts', $payload)->assertUnprocessable();
        $this->postJson('/api/shifts/draft', $payload)->assertUnprocessable();
        $payload['days'] = [$payload['days'][1]];
        $payload['days'][0]['start_time'] = '21:00';
        $this->postJson('/api/shifts', $payload)->assertOk()->assertJsonPath('id', $shiftId);
        $this->assertSame($original, (array) DB::table('t_shift_details')->where('id', $original['id'])->first());
        $this->assertDatabaseHas('t_shift_details', ['shift_id' => $shiftId, 'work_date' => '2026-10-13', 'start_time' => '21:00']);
        $this->getJson('/api/shifts/mine')->assertJsonPath('data.0.status', 2)->assertJsonCount(2, 'data.0.days');
        $this->postJson('/api/shifts/draft', ['shift_request_id' => $id, 'days' => []])->assertUnprocessable();
        DB::table('t_shift_schedule_days')->insert(['shift_request_id' => $id, 'work_date' => '2026-10-13', 'status' => 2]);
        $this->postJson('/api/shifts', $payload)->assertUnprocessable();
    }

    public function test_drafts_preserve_finalized_days_and_only_require_remaining_days_on_submit(): void
    {
        [$cast, $id, $payload] = $this->fixture();
        $this->postJson('/api/shifts/draft', $payload)->assertOk();
        DB::table('t_shift_schedule_days')->insert(['shift_request_id' => $id, 'work_date' => '2026-10-12', 'status' => 2]);
        $this->postJson('/api/shifts/draft', ['shift_request_id' => $id, 'days' => []])->assertOk();
        $this->getJson('/api/shifts/mine')->assertJsonCount(1, 'data.0.days')->assertJsonPath('data.0.days.0.work_date', '2026-10-12');
        $payload['days'] = [$payload['days'][1]];
        $this->postJson('/api/shifts', $payload)->assertOk();
        $this->getJson('/api/shifts/mine')->assertJsonPath('data.0.status', 2)->assertJsonCount(2, 'data.0.days');
    }

    public function test_home_uses_current_request_and_exposes_submission_time(): void
    {
        [$cast, $id, $payload] = $this->fixture();
        $this->getJson('/api/user')->assertJsonPath('home_path', '/submission');
        $this->postJson('/api/shifts/draft', $payload)->assertOk();
        $this->getJson('/api/user')->assertJsonPath('home_path', '/submission');
        $this->postJson('/api/shifts', $payload)->assertOk();
        $this->getJson('/api/user')->assertJsonPath('home_path', '/my_shifts');
        $response = $this->getJson('/api/shifts/mine')->assertJsonPath('data.0.request.id', $id);
        $this->assertNotNull($response->json('data.0.submitted_at'));
        $this->actingAs(User::factory()->create()->refresh())->getJson('/api/user')->assertJsonPath('home_path', '/submission');
        $this->actingAs($cast);
        DB::table('t_shift_requests')->where('id', $id)->update(['status' => 1]);
        $this->getJson('/api/user')->assertJsonPath('home_path', '/my_shifts');
        DB::table('t_shift_requests')->insert(['requested_by' => $cast->id, 'period_start' => '2026-10-19',
            'period_end' => '2026-10-20', 'submission_deadline' => '2099-10-09 23:59:59']);
        $this->getJson('/api/user')->assertJsonPath('home_path', '/submission');
    }
}
