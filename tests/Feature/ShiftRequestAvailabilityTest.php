<?php
namespace Tests\Feature;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class ShiftRequestAvailabilityTest extends TestCase
{
 use RefreshDatabase;
 public function test_defaults_and_registration_use_latest_confirmed_end(): void
 {
  $admin = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member, 1, 1, 1))->create([])->refresh();
  $this->actingAs($admin);
  $payload = ['store_id' => \Tests\Support\StoreFixtures::storeId(), 'requested_by' => $admin->id, 'period_start' => '2026-10-12', 'period_end' => '2026-10-16', 'submission_deadline' => '2099-10-09 23:59:59'];
  $id = DB::table('t_shift_requests')->insertGetId($payload);
  DB::table('t_shift_requests')->where('id', $id)->update(['status' => 1]);
  $draft = DB::table('t_shift_requests')->insertGetId([...$payload, 'period_end' => '2026-10-23']);
  $this->getJson('/api/shift-requests/defaults')->assertOk()->assertExactJson(['period_start' => '2026-10-19', 'submission_deadline' => null, 'confirmed_end' => '2026-10-16', 'has_unconfirmed_request' => true]);
  foreach (['2026-10-15', '2026-10-16'] as $start) {
   $this->postJson('/api/shift-requests', [...$payload, 'period_start' => $start])->assertUnprocessable()->assertJsonValidationErrors('period_start');
  }
  $this->postJson('/api/shift-requests', [...$payload, 'period_start' => '2026-10-19', 'period_end' => '2026-10-23'])->assertUnprocessable()->assertJsonValidationErrors('request');
  DB::table('t_shift_requests')->update(['status' => 1]);
  $this->postJson('/api/shift-requests', [...$payload, 'period_start' => '2026-10-26', 'period_end' => '2026-10-30'])->assertCreated();
 }
 public function test_defaults_without_confirmed_requests_leave_period_blank_and_use_next_weekday_deadline(): void
 {
  $admin = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member, 1, 1, 1))->create([])->refresh();
  $this->actingAs($admin);
  $this->travelTo(CarbonImmutable::parse('2026-10-16 23:59:59', 'Asia/Tokyo'));
  $expected = ['period_start' => null, 'submission_deadline' => '2026-10-19', 'confirmed_end' => null, 'has_unconfirmed_request' => false];
  $this->getJson('/api/shift-requests/defaults')->assertOk()->assertExactJson($expected);
  DB::table('t_shift_requests')->insert(['store_id' => \Tests\Support\StoreFixtures::storeId(), 'requested_by' => $admin->id, 'period_start' => '2026-10-12', 'period_end' => '2026-10-16', 'submission_deadline' => '2026-10-09 23:59:59', 'status' => 1, 'deleted_at' => now()]);
  $this->getJson('/api/shift-requests/defaults')->assertOk()->assertExactJson($expected);
  $this->travelBack();
 }
 public function test_deadline_day_allows_submit_and_draft_until_next_day_and_confirmation_blocks_both(): void
 {
  $cast = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create()->refresh();
  $this->actingAs($cast);
  $id = DB::table('t_shift_requests')->insertGetId(['store_id' => \Tests\Support\StoreFixtures::storeId(), 'requested_by' => $cast->id, 'period_start' => '2026-10-12', 'period_end' => '2026-10-12', 'submission_deadline' => '2026-10-09 00:00:00']);
  $payload = ['shift_request_id' => $id, 'days' => [['work_date' => '2026-10-12', 'work' => false, 'has_reservation' => false]]];
  $this->travelTo(CarbonImmutable::parse('2026-10-09 23:59:59', 'Asia/Tokyo'));
  $this->getJson('/api/shift-requests/accepting')->assertJsonCount(1, 'data');
  $this->postJson('/api/shifts/draft', $payload)->assertOk();
  $this->postJson('/api/shifts', $payload)->assertOk();
  $this->travelTo(CarbonImmutable::parse('2026-10-10 00:00:00', 'Asia/Tokyo'));
  $this->getJson('/api/shift-requests/accepting')->assertJsonCount(0, 'data');
  $this->postJson('/api/shifts', $payload)->assertUnprocessable();
  $this->postJson('/api/shifts/draft', $payload)->assertUnprocessable();
  $this->travelBack();
  DB::table('t_shift_requests')->where('id', $id)->update(['submission_deadline' => '2099-10-09 23:59:59']);
  DB::table('t_shift_requests')->where('id', $id)->update(['status' => 1]);
  $this->postJson('/api/shifts', $payload)->assertUnprocessable();
  $this->postJson('/api/shifts/draft', $payload)->assertUnprocessable();
 }
}
