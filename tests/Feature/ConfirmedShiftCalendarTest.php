<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConfirmedShiftCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/shifts/confirmed-calendar?from=2026-10-19&to=2026-10-23';

    public function test_only_managers_leader_mama_and_admin_can_view_the_calendar(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        foreach ([
            [1, 1, 0, true], [1, 2, 0, true], [1, 3, 0, false],
            [1, 4, 0, true], [1, 5, 0, false], [2, 1, 0, true],
            [2, 2, 0, false], [2, 3, 0, false], [1, 5, 1, true], [2, 3, 1, true],
        ] as [$type, $role, $admin, $allowed]) {
            $user = User::factory()->create(['user_type' => $type, 'role_type' => $role, 'auth_type' => $admin]);
            $this->actingAs($user)->getJson(self::URL)->assertStatus($allowed ? 200 : 403);
        }
    }

    public function test_calendar_only_publishes_finalized_days_and_active_working_casts(): void
    {
        $leader = User::factory()->create(['user_type' => 1, 'role_type' => 4]);
        $request = DB::table('t_shift_requests')->insertGetId([
            'requested_by' => $leader->id, 'period_start' => '2026-10-19', 'period_end' => '2026-10-23',
            'submission_deadline' => '2026-10-16 23:59:59', 'status' => 0,
        ]);
        $working = User::factory()->create(['name' => 'Working']);
        $earlier = User::factory()->create(['name' => 'Earlier']);
        $off = User::factory()->create(['name' => 'Off']);
        $deleted = User::factory()->create(['name' => 'Deleted']);
        $deleted->delete();
        foreach ([['2026-10-19', 2], ['2026-10-20', 1], ['2026-10-21', 2], ['2026-10-22', 2], ['2026-10-23', 2]] as [$date, $status]) {
            $day = DB::table('t_shift_schedule_days')->insertGetId([
                'shift_request_id' => $request, 'work_date' => $date, 'status' => $status,
                'deleted_at' => $date === '2026-10-22' ? now() : null,
            ]);
            if ($date === '2026-10-21') continue; // Finalized with no attendance.
            foreach ([$working, $earlier, $off, $deleted, $leader] as $cast) {
                DB::table('t_shift_assignments')->insert([
                    'shift_schedule_day_id' => $day, 'user_id' => $cast->id, 'work' => $cast->id !== $off->id,
                    'start_time' => $cast->id === $earlier->id ? '20:00:00' : '21:00:00',
                    'end_time' => '00:00:00', 'ends_next_day' => true,
                    'note' => 'private memo', 'has_reservation' => true,
                    'deleted_at' => $date === '2026-10-23' ? now() : null,
                ]);
            }
        }
        $data = $this->actingAs($leader)->getJson(self::URL)->assertOk()->assertJsonCount(5, 'data')->json('data');
        $this->assertSame(['date' => '2026-10-19', 'confirmed' => true, 'count' => 2, 'casts' => [
            ['id' => $earlier->ulid, 'name' => 'Earlier', 'start' => '20:00', 'end' => '24:00'],
            ['id' => $working->ulid, 'name' => 'Working', 'start' => '21:00', 'end' => '24:00'],
        ]], $data[0]);
        $this->assertSame(['date' => '2026-10-20', 'confirmed' => false, 'count' => 0, 'casts' => []], $data[1]);
        $this->assertSame(['date' => '2026-10-21', 'confirmed' => true, 'count' => 0, 'casts' => []], $data[2]);
        $this->assertFalse($data[3]['confirmed']);
        $this->assertSame([], $data[3]['casts']);
        $this->assertSame(0, $data[4]['count']);
        DB::table('t_shift_requests')->where('id', $request)->update(['deleted_at' => now()]);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.confirmed', false)->assertJsonPath('data.0.casts', []);
    }

    public function test_viewers_cannot_use_management_apis(): void
    {
        foreach ([[1, 4], [2, 1]] as [$type, $role]) {
            $user = User::factory()->create(['user_type' => $type, 'role_type' => $role]);
            $this->actingAs($user)->getJson('/api/shifts/status')->assertForbidden();
            $this->postJson('/api/shift-requests', [])->assertForbidden();
            $this->putJson('/api/admin/shift-requests/1/schedule/days/2026-10-19', [])->assertForbidden();
            $this->postJson('/api/admin/shift-requests/1/schedule/confirm', [])->assertForbidden();
        }
    }

    public function test_date_range_is_required_valid_ordered_and_bounded(): void
    {
        $this->actingAs(User::factory()->create(['user_type' => 2, 'role_type' => 1]));
        foreach (['', '?from=2026-02-30&to=2026-03-01', '?from=2026-10-23&to=2026-10-19',
            '?from=2026-01-01&to=2026-12-31'] as $query) {
            $this->getJson('/api/shifts/confirmed-calendar'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/shifts/confirmed-calendar?from=2026-10-19&to=2026-10-19')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.confirmed', false);
    }
}
