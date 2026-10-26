<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShiftScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $admin = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member, 1, 1, 1))->create([])->refresh();
        $cast = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
        $id = DB::table('t_shift_requests')->insertGetId(['store_id' => \Tests\Support\StoreFixtures::storeId(), 'requested_by' => $admin->id,
            'period_start' => '2026-10-16', 'period_end' => '2026-10-19', 'submission_deadline' => '2026-10-09 23:59:59']);
        $this->submitAvailable($cast, $id);
        return [$admin, $cast, $id, "/api/admin/shift-requests/$id/schedule"];
    }

    private function submitAvailable(User $cast, int $requestId): int
    {
        $shift = DB::table('t_shifts')->insertGetId(['shift_request_id' => $requestId, 'user_id' => $cast->id, 'status' => 2]);
        foreach (['2026-10-16', '2026-10-19'] as $date) {
            DB::table('t_shift_details')->insert(['shift_id' => $shift, 'work_date' => $date, 'availability' => 1,
                'start_time' => '21:00:00', 'end_time' => '00:00:00', 'ends_next_day' => true,
                'has_reservation' => true, 'has_companion' => false, 'note' => '希望']);
        }
        return $shift;
    }

    private function casts(User $cast): array
    {
        return [['id' => $cast->ulid, 'work' => true, 'start' => '20:00', 'end' => '24:00', 'reserved' => true, 'note' => '予約']];
    }

    public function test_required_count_is_saved_per_day_and_restored_in_review(): void
    {
        [$admin, $cast, $id, $url] = $this->fixture();
        $this->actingAs($admin);
        $this->getJson("$url/days/2026-10-16")->assertOk()->assertJsonPath('required_count', 0);
        $this->putJson("$url/days/2026-10-16", ['casts' => $this->casts($cast), 'required_count' => 4])->assertOk();
        $this->getJson("$url/days/2026-10-16")->assertOk()->assertJsonPath('required_count', 4);
        $this->getJson($url)->assertOk()->assertJsonPath('days.0.required_count', 4)->assertJsonPath('days.0.count', 1)
            ->assertJsonPath('days.1.required_count', 0);
        // Older callers can omit the field without resetting a saved target.
        $this->putJson("$url/days/2026-10-16", ['casts' => $this->casts($cast)])->assertOk();
        $this->getJson("$url/days/2026-10-16")->assertOk()->assertJsonPath('required_count', 4);
        $this->putJson("$url/days/2026-10-16", ['casts' => $this->casts($cast), 'required_count' => 0])->assertOk();
        $this->assertDatabaseHas('t_shift_schedule_days', ['shift_request_id' => $id, 'work_date' => '2026-10-16', 'required_count' => 0]);
    }

    public function test_invalid_required_counts_do_not_save_the_day(): void
    {
        [$admin, $cast, $id, $url] = $this->fixture();
        $this->actingAs($admin);
        foreach ([-1, 1.5, 'abc', null, 65536] as $count) {
            $this->putJson("$url/days/2026-10-16", ['casts' => $this->casts($cast), 'required_count' => $count])
                ->assertUnprocessable()->assertJsonValidationErrors('required_count');
        }
        $this->assertDatabaseCount('t_shift_schedule_days', 0);
    }

    public function test_confirmation_is_blocked_through_deadline_day_and_allowed_from_next_day(): void
    {
        [$admin, $cast, $id, $url] = $this->fixture();
        DB::table('t_shift_requests')->where('id', $id)->update(['submission_deadline' => '2026-10-09 00:00:00']);
        $this->actingAs($admin);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Tokyo'));
        foreach (['2026-10-16', '2026-10-19'] as $date) {
            $this->putJson("$url/days/$date", ['casts' => $this->casts($cast)])->assertOk();
        }
        foreach (['2026-10-08 12:00:00', '2026-10-09 23:59:59'] as $now) {
            $this->travelTo(\Carbon\CarbonImmutable::parse($now, 'Asia/Tokyo'));
            $this->getJson($url)->assertOk()->assertJsonPath('confirmation_available', false);
            foreach ([[], ['from' => '2026-10-16', 'to' => '2026-10-16']] as $range) {
                $this->postJson("$url/confirm", $range)->assertUnprocessable()
                    ->assertJsonFragment(['提出期限内はシフトを確定できません。提出期限の翌日から確定できます。']);
            }
            $this->assertDatabaseMissing('t_shift_schedule_days', ['shift_request_id' => $id, 'status' => 2]);
            $this->assertDatabaseHas('t_shift_requests', ['id' => $id, 'status' => 0]);
        }
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-10 00:00:00', 'Asia/Tokyo'));
        $this->getJson($url)->assertJsonPath('confirmation_available', true);
        $this->postJson("$url/confirm")->assertOk();
        $this->assertDatabaseHas('t_shift_requests', ['id' => $id, 'status' => 1]);
        $this->travelBack();
    }

    public function test_decisions_persist_and_period_confirmation_closes_submissions(): void
    {
        [$admin, $cast, $id, $url] = $this->fixture();
        $this->actingAs($admin);
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'days')->assertJsonPath('days.0.confirmed', false);
        $this->postJson("$url/confirm")->assertUnprocessable();
        $casts = $this->casts($cast);
        $this->putJson("$url/days/2026-10-16", ['casts' => $casts])->assertOk();
        $this->assertDatabaseHas('t_shift_schedule_days', ['work_date' => '2026-10-16', 'status' => 1, 'decided_by' => $admin->id]);
        $this->assertDatabaseHas('t_shift_assignments', ['user_id' => $cast->id, 'start_time' => '20:00', 'end_time' => '00:00', 'ends_next_day' => 1]);
        $this->getJson("$url/days/2026-10-16")->assertOk()->assertJsonPath('casts.0.end', '24:00')->assertJsonPath('casts.0.reserved', true);
        $this->getJson($url)->assertJsonPath('days.0.count', 1)->assertJsonPath('days.0.confirmed', true);
        $casts[0]['work'] = false;
        $this->putJson("$url/days/2026-10-16", ['casts' => $casts])->assertOk();
        $this->assertDatabaseCount('t_shift_schedule_days', 1);
        $this->assertDatabaseCount('t_shift_assignments', 1);
        $this->assertDatabaseHas('t_shift_assignments', ['work' => 0, 'start_time' => null, 'end_time' => null, 'has_reservation' => 0]);
        $this->postJson("$url/confirm")->assertUnprocessable();
        $this->putJson("$url/days/2026-10-19", ['casts' => $casts])->assertOk();
        $this->postJson("$url/confirm")->assertOk();
        $this->assertDatabaseHas('t_shift_schedule_days', ['shift_request_id' => $id, 'status' => 2, 'confirmed_by' => $admin->id]);
        $this->assertNotNull(DB::table('t_shift_schedule_days')->value('confirmed_at'));
        $this->getJson('/api/shift-requests/accepting')->assertJsonCount(0, 'data');
        $this->getJson('/api/shift-requests/defaults')->assertJsonPath('period_start', '2026-10-20');
        $this->putJson("$url/days/2026-10-16", ['casts' => $casts])->assertOk();
        $this->postJson("$url/confirm")->assertUnprocessable();
    }

    public function test_assignment_initial_values_use_only_submitted_shifts_and_saved_values_take_precedence(): void
    {
        [$admin, $cast, $id, $url] = $this->fixture();
        $shift = DB::table('t_shifts')->where('user_id', $cast->id)->value('id');
        DB::table('t_shifts')->where('id', $shift)->update(['status' => 1]);
        $this->actingAs($admin)->getJson("$url/days/2026-10-16")->assertOk()->assertJsonCount(0, 'casts');
        DB::table('t_shifts')->where('id', $shift)->update(['status' => 2]);
        $this->getJson("$url/days/2026-10-16")->assertJsonPath('casts.0.work', true)->assertJsonPath('casts.0.start', '21:00')->assertJsonPath('casts.0.end', '24:00');
        $this->putJson("$url/days/2026-10-16", ['casts' => $this->casts($cast)])->assertOk();
        $this->getJson("$url/days/2026-10-16")->assertJsonPath('casts.0.start', '20:00');
    }

    public function test_assignments_only_include_available_submissions_for_the_selected_day(): void
    {
        [$admin, $cast, $id, $url] = $this->fixture();
        $missing = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create();
        $draft = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create();
        $draftShift = $this->submitAvailable($draft, $id);
        DB::table('t_shifts')->where('id', $draftShift)->update(['status' => 1]);
        $unavailable = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create();
        $unavailableShift = $this->submitAvailable($unavailable, $id);
        DB::table('t_shift_details')->where('shift_id', $unavailableShift)->where('work_date', '2026-10-16')->update(['availability' => 0]);
        $this->actingAs($admin)->getJson("$url/days/2026-10-16")->assertOk()
            ->assertJsonCount(1, 'casts')->assertJsonPath('casts.0.id', $cast->ulid);
        $this->getJson("$url/days/2026-10-19")->assertJsonCount(2, 'casts');
        $this->putJson("$url/days/2026-10-16", ['casts' => [...$this->casts($cast), ...$this->casts($unavailable)]])->assertUnprocessable();
        $casts = $this->casts($cast);
        $casts[0]['work'] = false;
        $this->putJson("$url/days/2026-10-16", ['casts' => $casts])->assertOk();
        $this->getJson("$url/days/2026-10-16")->assertJsonCount(1, 'casts')->assertJsonPath('casts.0.work', false);
        // Zero eligible casts can still be decided as a day with no workers.
        DB::table('t_shift_details')->where('work_date', '2026-10-19')->update(['availability' => 0]);
        $this->getJson("$url/days/2026-10-19")->assertJsonCount(0, 'casts');
        $this->putJson("$url/days/2026-10-19", ['casts' => []])->assertOk();
        $this->getJson($url)->assertJsonPath('days.1.confirmed', true)->assertJsonPath('days.1.count', 0);
    }

    public function test_invalid_dates_times_and_memberships_do_not_write(): void
    {
        [$admin, $cast, $id, $url] = $this->fixture();
        $this->actingAs($admin);
        foreach (['2026-10-17', '2026-10-15', '2026-10-20', '2026-02-30', 'invalid'] as $date) {
            $this->putJson("$url/days/$date", ['casts' => $this->casts($cast)])->assertUnprocessable();
        }
        foreach (['19:45', '20:60', '22:15', '24:00'] as $start) {
            $casts = $this->casts($cast); $casts[0]['start'] = $start;
            $this->putJson("$url/days/2026-10-16", ['casts' => $casts])->assertUnprocessable();
        }
        foreach (['21:45', '00:00', '24:15'] as $end) {
            $casts = $this->casts($cast); $casts[0]['end'] = $end;
            $this->putJson("$url/days/2026-10-16", ['casts' => $casts])->assertUnprocessable();
        }
        $this->putJson("$url/days/2026-10-16", ['casts' => []])->assertUnprocessable();
        $casts = $this->casts($cast); $casts[0]['id'] = $admin->ulid;
        $this->putJson("$url/days/2026-10-16", ['casts' => $casts])->assertUnprocessable();
        $casts = $this->casts($cast);
        $this->putJson("$url/days/2026-10-16", ['casts' => [$casts[0], $casts[0]]])->assertUnprocessable();
        $this->assertDatabaseCount('t_shift_schedule_days', 0);
        $this->assertDatabaseCount('t_shift_assignments', 0);
    }

    public function test_new_cast_requires_daily_decisions_again(): void
    {
        [$admin, $cast, $id, $url] = $this->fixture();
        $this->actingAs($admin);
        foreach (['2026-10-16', '2026-10-19'] as $date) $this->putJson("$url/days/$date", ['casts' => $this->casts($cast)])->assertOk();
        $newCast = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create();
        $this->submitAvailable($newCast, $id);
        $this->getJson($url)->assertJsonPath('days.0.confirmed', false);
        $this->postJson("$url/confirm")->assertUnprocessable();
        $this->assertDatabaseHas('t_shift_requests', ['id' => $id, 'status' => 0]);
    }

    public function test_endpoints_require_admin_and_reject_missing_or_deleted_requests(): void
    {
        [$admin, $cast, $id, $url] = $this->fixture();
        $this->getJson($url)->assertUnauthorized();
        $this->putJson("$url/days/2026-10-16", ['casts' => $this->casts($cast)])->assertUnauthorized();
        $this->postJson("$url/confirm")->assertUnauthorized();
        $this->actingAs($cast)->getJson($url)->assertForbidden();
        $this->getJson("$url/days/2026-10-16")->assertForbidden();
        $this->putJson("$url/days/2026-10-16", ['casts' => $this->casts($cast)])->assertForbidden();
        $this->postJson("$url/confirm")->assertForbidden();
        $this->actingAs($admin)->getJson('/api/admin/shift-requests/999/schedule')->assertNotFound();
        DB::table('t_shift_requests')->where('id', $id)->update(['deleted_at' => now()]);
        $this->getJson($url)->assertNotFound();
        $this->putJson("$url/days/2026-10-16", ['casts' => $this->casts($cast)])->assertNotFound();
    }
    public function test_cast_sees_only_own_assignments_in_confirmed_periods(): void
    {
        [$admin, $cast, $id, $url] = $this->fixture();
        $other = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
        $this->submitAvailable($other, $id);
        $casts = [...$this->casts($cast), ...$this->casts($other)];
        $casts[1]['start'] = '21:00';
        $this->actingAs($admin);
        foreach (['2026-10-16', '2026-10-19'] as $date) $this->putJson("$url/days/$date", ['casts' => $casts])->assertOk();
        $this->actingAs($cast)->getJson('/api/shifts/confirmed')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->postJson("$url/confirm")->assertOk();
        $this->getJson('/api/shifts/confirmed')->assertOk();
        $this->actingAs($cast)->getJson('/api/shifts/confirmed')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonCount(2, 'data.0.shifts')->assertJsonPath('data.0.finalized_dates', ['2026-10-16', '2026-10-19'])->assertJsonPath('data.0.shifts.0.start', '20:00')
            ->assertJsonPath('data.0.shifts.0.end', '24:00');
        $this->actingAs($other)->getJson('/api/shifts/confirmed')->assertJsonPath('data.0.shifts.0.start', '21:00');
        DB::table('t_shift_requests')->where('id', $id)->update(['deleted_at' => now()]);
        $this->getJson('/api/shifts/confirmed')->assertJsonCount(0, 'data');
    }

    public function test_partial_confirmation_blocks_new_requests_until_every_weekday_is_finalized(): void
    {
        [$admin, $cast, $id, $url] = $this->fixture();
        DB::table('t_shift_requests')->where('id', $id)->update(['submission_deadline' => '2026-10-01 23:59:59']);
        $this->actingAs($admin);
        $this->getJson('/api/shift-requests/accepting')->assertJsonPath('data.0.id', $id);
        $payload = ['period_start' => '2026-10-20', 'period_end' => '2026-10-23', 'submission_deadline' => '2026-10-19 23:59:59'];
        $this->postJson('/api/shift-requests', $payload)->assertUnprocessable();
        $this->postJson("$url/confirm", ['from' => '2026-10-15', 'to' => '2026-10-16'])->assertUnprocessable();
        $this->putJson("$url/days/2026-10-16", ['casts' => $this->casts($cast)])->assertOk();
        $this->postJson("$url/confirm", ['from' => '2026-10-16', 'to' => '2026-10-16'])->assertOk();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('t_shift_schedules'));
        $this->assertDatabaseHas('t_shift_schedule_days', ['shift_request_id' => $id, 'work_date' => '2026-10-16', 'status' => 2, 'confirmed_by' => $admin->id]);
        $this->assertNotNull(DB::table('t_shift_schedule_days')->where('shift_request_id', $id)->value('confirmed_at'));
        $this->assertDatabaseHas('t_shift_requests', ['id' => $id, 'status' => 0]);
        $this->getJson($url)->assertJsonPath('days.0.finalized', true)->assertJsonPath('days.1.finalized', false);
        $this->putJson("$url/days/2026-10-16", ['casts' => $this->casts($cast)])->assertOk();
        $this->postJson('/api/shift-requests', $payload)->assertUnprocessable();
        $this->actingAs($cast)->getJson('/api/shifts/confirmed')->assertJsonCount(1, 'data.0.shifts')->assertJsonPath('data.0.finalized_dates', ['2026-10-16']);
        $this->actingAs($admin);
        $casts = $this->casts($cast); $casts[0]['work'] = false;
        $this->putJson("$url/days/2026-10-19", ['casts' => $casts])->assertOk();
        $this->postJson('/api/shift-requests', $payload)->assertUnprocessable();
        $this->postJson("$url/confirm", ['from' => '2026-10-19', 'to' => '2026-10-19'])->assertOk();
        $this->assertDatabaseHas('t_shift_requests', ['id' => $id, 'status' => 1]);
        $this->postJson('/api/shift-requests', $payload)->assertCreated();
        $this->assertDatabaseCount('t_shift_requests', 2);
    }

    public function test_admin_can_add_unavailable_and_unsubmitted_casts_to_a_finalized_day(): void
    {
        [$admin, $cast, $id, $url] = $this->fixture();
        $unavailable = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
        $shift = $this->submitAvailable($unavailable, $id);
        DB::table('t_shift_details')->where('shift_id', $shift)->update(['availability' => 0]);
        $missing = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
        $deleted = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
        $deleted->delete();
        $this->actingAs($admin);
        $payload = ['casts' => $this->casts($cast)];
        $this->getJson("$url/days/2026-10-16")->assertJsonCount(0, 'candidates');
        $this->putJson("$url/days/2026-10-16", ['casts' => [...$payload['casts'], ...$this->casts($missing)]])->assertUnprocessable();
        $this->putJson("$url/days/2026-10-16", $payload)->assertOk();
        $this->postJson("$url/confirm", ['from' => '2026-10-16', 'to' => '2026-10-16'])->assertOk();
        $this->getJson("$url/days/2026-10-16")->assertJsonCount(2, 'candidates')
            ->assertJsonFragment(['id' => $missing->ulid, 'name' => $missing->name]);
        $payload['casts'] = [...$payload['casts'], ...$this->casts($unavailable), ...$this->casts($missing)];
        $invalid = $payload;
        $invalid['casts'][2]['start'] = '19:00';
        $this->putJson("$url/days/2026-10-16", $invalid)->assertUnprocessable();
        $this->putJson("$url/days/2026-10-16", $payload)->assertOk();
        $this->getJson("$url/days/2026-10-16")->assertJsonPath('confirmed', true)
            ->assertJsonCount(3, 'casts')->assertJsonCount(0, 'candidates');
        $this->getJson($url)->assertJsonPath('days.0.finalized', true)->assertJsonPath('days.0.count', 3);
        $this->putJson("$url/days/2026-10-16", ['casts' => $this->casts($cast)])->assertUnprocessable();
        $this->putJson("$url/days/2026-10-16", ['casts' => [...$payload['casts'], ...$this->casts($deleted)]])->assertUnprocessable();
        $payload['casts'][2]['start'] = '21:00';
        $this->putJson("$url/days/2026-10-16", $payload)->assertOk();
        $this->assertDatabaseHas('t_shift_details', ['shift_id' => $shift, 'work_date' => '2026-10-16', 'availability' => 0]);
        $this->assertDatabaseMissing('t_shifts', ['user_id' => $missing->id]);
        $this->actingAs($missing)->getJson('/api/shifts/confirmed')->assertJsonPath('data.0.shifts.0.start', '21:00');
        $this->actingAs($admin)->getJson('/api/shifts/confirmed-calendar?from=2026-10-16&to=2026-10-16')
            ->assertOk()->assertJsonPath('data.0.count', 3);
        $this->actingAs($missing)->putJson("$url/days/2026-10-16", $payload)->assertForbidden();
    }

    public function test_editing_finalized_day_preserves_publication_and_rejects_invalid_changes(): void
    {
        [$admin, $cast, $id, $url] = $this->fixture();
        $this->actingAs($admin);
        foreach (['2026-10-16', '2026-10-19'] as $date) {
            $this->putJson("$url/days/$date", ['casts' => $this->casts($cast)])->assertOk();
        }
        $this->postJson("$url/confirm")->assertOk();
        $day = DB::table('t_shift_schedule_days')->where('shift_request_id', $id)->where('work_date', '2026-10-16')->first();
        $this->getJson('/api/shift-requests/accepting')->assertJsonCount(0, 'data');
        $this->getJson('/api/shift-requests/accepting?include_confirmed=1')->assertJsonPath('data.0.id', $id);
        $casts = $this->casts($cast);
        $casts[0]['start'] = '21:00';
        $this->putJson("$url/days/2026-10-16", ['casts' => $casts])->assertOk();
        $this->getJson("$url/days/2026-10-16")->assertJsonPath('confirmed', true)->assertJsonPath('casts.0.start', '21:00');
        $this->assertDatabaseHas('t_shift_schedule_days', ['id' => $day->id, 'status' => 2, 'confirmed_at' => $day->confirmed_at]);
        $this->assertDatabaseHas('t_shift_requests', ['id' => $id, 'status' => 1]);
        $invalid = $casts; $invalid[0]['start'] = '23:00';
        $this->putJson("$url/days/2026-10-16", ['casts' => $invalid])->assertUnprocessable();
        $this->actingAs($cast)->getJson('/api/shifts/confirmed')->assertJsonPath('data.0.shifts.0.start', '21:00');
        $this->putJson("$url/days/2026-10-16", ['casts' => $casts])->assertForbidden();
        $this->actingAs($admin);
        $casts[0]['work'] = false;
        $this->putJson("$url/days/2026-10-16", ['casts' => $casts])->assertOk();
        $this->actingAs($cast)->getJson('/api/shifts/confirmed')->assertJsonCount(1, 'data.0.shifts')->assertJsonPath('data.0.shifts.0.date', '2026-10-19');
        $this->actingAs($admin);
        $casts[0]['work'] = true;
        $this->putJson("$url/days/2026-10-16", ['casts' => $casts])->assertOk();
        $this->actingAs($cast)->getJson('/api/shifts/confirmed')->assertJsonCount(2, 'data.0.shifts');
    }

}
