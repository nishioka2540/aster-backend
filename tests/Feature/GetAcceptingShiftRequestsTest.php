<?php
namespace Tests\Feature;
use App\Models\User;
use App\Infrastructure\Persistence\Eloquent\ShiftRequestModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class GetAcceptingShiftRequestsTest extends TestCase
{
    use RefreshDatabase;
    public function test_only_accepting_and_non_deleted_requests_are_returned(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-09 22:00:00', 'Asia/Tokyo'));
        $user = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create();
        $payload = ['store_id' => \Tests\Support\StoreFixtures::storeId(), 'requested_by' => $user->id, 'period_start' => '2026-10-12',
            'period_end' => '2026-10-16', 'submission_deadline' => '2026-10-09 23:59:59',
            'notice' => '提出をお願いします。'];
        $first = ShiftRequestModel::create($payload);
        $second = ShiftRequestModel::create($payload);
        ShiftRequestModel::create([...$payload, 'submission_deadline' => '2026-10-08 23:59:59']);
        $confirmed = ShiftRequestModel::create($payload);
        \Illuminate\Support\Facades\DB::table('t_shift_requests')->where('id', $confirmed->id)->update(['status' => 1]);
        $deleted = ShiftRequestModel::create($payload);
        $deleted->delete();
        $this->actingAs($user)->getJson('/api/shift-requests/accepting')->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.0.period_start', '2026-10-12')
            ->assertJsonPath('data.0.period_end', '2026-10-16');
    }
    public function test_empty_result_is_an_empty_list(): void
    {
        $this->actingAs(User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create())->getJson('/api/shift-requests/accepting')
            ->assertOk()->assertExactJson(['data' => []]);
    }
    public function test_review_excludes_expired_periods_using_the_current_japan_date(): void
    {
        // UTC is still October 22, but the shop date is October 23.
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-22 15:30:00', 'UTC'));
        $manager = User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create(['user_type' => 1, 'role_type' => 2]);
        $payload = ['store_id' => \Tests\Support\StoreFixtures::storeId(), 'requested_by' => $manager->id, 'period_start' => '2026-10-19',
            'submission_deadline' => '2026-10-16 23:59:59'];
        $expired = ShiftRequestModel::create([...$payload, 'period_end' => '2026-10-22']);
        $today = ShiftRequestModel::create([...$payload, 'period_end' => '2026-10-23']);
        $future = ShiftRequestModel::create([...$payload, 'period_start' => '2026-10-26', 'period_end' => '2026-10-30']);
        \Illuminate\Support\Facades\DB::table('t_shift_requests')->where('id', $today->id)->update(['status' => 1]);
        $deleted = ShiftRequestModel::create([...$payload, 'period_end' => '2026-10-30']);
        $deleted->delete();
        foreach ([1, 2] as $role) {
            $this->actingAs(User::factory()->afterCreating(fn ($member) => \Tests\Support\StoreFixtures::enroll($member))->create(['user_type' => 1, 'role_type' => $role]))
                ->getJson('/api/shift-requests/accepting?include_confirmed=1&upcoming_only=1')
                ->assertOk()->assertJsonCount(2, 'data')
                ->assertJsonPath('data.0.id', $future->id)->assertJsonPath('data.1.id', $today->id);
        }
        $this->getJson('/api/shift-requests/accepting?include_confirmed=1')->assertJsonCount(3, 'data');
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-23 15:30:00', 'UTC'));
        $this->getJson('/api/shift-requests/accepting?include_confirmed=1&upcoming_only=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $future->id);
        $future->delete();
        $this->getJson('/api/shift-requests/accepting?include_confirmed=1&upcoming_only=1')
            ->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/shift-requests/accepting')->assertUnauthorized();
    }
}
