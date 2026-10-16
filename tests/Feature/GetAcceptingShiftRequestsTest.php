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
        $user = User::factory()->create();
        $payload = ['requested_by' => $user->id, 'period_start' => '2026-10-12',
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
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.0.period_start', '2026-10-12')
            ->assertJsonPath('data.0.period_end', '2026-10-16');
    }
    public function test_empty_result_is_an_empty_list(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/shift-requests/accepting')
            ->assertOk()->assertExactJson(['data' => []]);
    }
    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/shift-requests/accepting')->assertUnauthorized();
    }
}
