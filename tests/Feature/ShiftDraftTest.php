<?php

namespace Tests\Feature;

use App\Models\User;
use Enum\ShiftStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShiftDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_draft_is_restored_and_can_be_submitted_without_duplicates(): void
    {
        $cast = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
        $admin = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
        \Tests\Support\StoreFixtures::enroll($admin, 1, 1, 1);
        $id = DB::table('t_shift_requests')->insertGetId([
            'store_id' => \Tests\Support\StoreFixtures::storeId(), 'requested_by' => $admin->id, 'period_start' => '2026-10-12', 'period_end' => '2026-10-13',
            'submission_deadline' => '2099-10-09 23:59:59',
        ]);
        $payload = ['shift_request_id' => $id, 'days' => [[
            'work_date' => '2026-10-12', 'work' => true, 'start_time' => null, 'end_time' => null,
            'has_reservation' => true, 'note' => '下書き',
        ]]];
        $this->postJson('/api/shifts/draft', $payload)->assertUnauthorized();
        $this->actingAs($admin)->postJson('/api/shifts/draft', $payload)->assertOk();
        $response = $this->actingAs($cast)->postJson('/api/shifts/draft', [...$payload, 'user_id' => $admin->id])->assertOk();
        $this->assertDatabaseHas('t_shifts', ['id' => $response->json('id'), 'user_id' => $cast->id,
            'status' => ShiftStatus::Draft->value, 'submitted_at' => null]);
        $this->getJson('/api/shifts/mine')->assertOk()->assertJsonPath('data.0.days.0.note', '下書き')
            ->assertJsonPath('data.0.days.0.start_time', null);
        $this->actingAs($admin)->getJson('/api/shifts/status')->assertOk()
            ->assertJsonPath('data.0.unsubmitted.0.id', $cast->ulid)->assertJsonCount(0, 'data.0.submitted');
        $this->actingAs($cast)->postJson('/api/shifts', $payload)->assertUnprocessable();
        $payload['days'][0]['start_time'] = '20:00';
        $payload['days'][0]['end_time'] = '00:00';
        $payload['days'][] = ['work_date' => '2026-10-13', 'work' => false, 'has_reservation' => false];
        $this->postJson('/api/shifts', $payload)->assertOk()->assertJsonPath('id', $response->json('id'));
        $this->assertDatabaseHas('t_shifts', ['id' => $response->json('id'), 'status' => ShiftStatus::Submitted->value]);
        $this->assertDatabaseCount('t_shifts', 2);
        $other = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
        $this->actingAs($other)->getJson('/api/shifts/mine')->assertOk()->assertJsonPath('data', []);
        $this->actingAs($cast)->postJson('/api/shifts/draft', ['shift_request_id' => $id, 'days' => []])->assertUnprocessable();
        $this->getJson('/api/shifts/mine')->assertJsonPath('data.0.status', ShiftStatus::Submitted->value)
            ->assertJsonCount(2, 'data.0.days');
        $payload['days'][0]['work_date'] = '2026-10-17';
        $this->postJson('/api/shifts/draft', $payload)->assertUnprocessable();
        DB::table('t_shift_requests')->where('id', $id)->update(['submission_deadline' => '2000-01-01 00:00:00']);
        $this->postJson('/api/shifts/draft', ['shift_request_id' => $id, 'days' => []])->assertUnprocessable();
    }
}
