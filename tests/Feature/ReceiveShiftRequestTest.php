<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiveShiftRequestTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'period_start' => '2026-10-12',
            'period_end' => '2026-10-16',
            'submission_deadline' => '2026-10-09 23:59:59',
            'notice' => '提出をお願いします。',
        ];
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->auth_type = 1;
        $user->save();
        return $user;
    }

    public function test_admin_can_persist_request_with_authenticated_owner(): void
    {
        $admin = $this->admin();
        $response = $this->actingAs($admin)->postJson('/api/shift-requests', [
            ...$this->payload(), 'requested_by' => 999, 'status' => 9,
        ])->assertCreated()->assertJsonStructure(['id', 'message']);
        $this->assertDatabaseCount('t_shift_requests', 1);
        $this->assertDatabaseHas('t_shift_requests', [
            'id' => $response->json('id'),
            'requested_by' => $admin->id,
            ...$this->payload(),
            'deleted_at' => null,
        ]);
    }

    public function test_guest_and_cast_cannot_send_requests(): void
    {
        $this->postJson('/api/shift-requests', $this->payload())->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->postJson('/api/shift-requests', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('t_shift_requests', 0);
    }

    public function test_weekends_and_reversed_period_are_rejected(): void
    {
        $this->actingAs($this->admin());
        foreach (['period_start', 'period_end', 'submission_deadline'] as $field) {
            $payload = $this->payload();
            $payload[$field] = $field === 'submission_deadline' ? '2026-10-11 23:59:59' : '2026-10-11';
            $this->postJson('/api/shift-requests', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $payload = $this->payload();
        $payload['period_end'] = '2026-10-09';
        $this->postJson('/api/shift-requests', $payload)->assertUnprocessable()->assertJsonValidationErrors('period_end');
        $this->assertDatabaseCount('t_shift_requests', 0);
    }

    public function test_csrf_token_is_required_and_valid_token_is_accepted(): void
    {
        $this->actingAs($this->admin());
        // Laravel skips CSRF in testing; enable the real middleware behavior here.
        $this->app['env'] = 'local';
        $this->postJson('/api/shift-requests', $this->payload())->assertStatus(419);
        $this->assertDatabaseCount('t_shift_requests', 0);
        $this->withSession(['_token' => 'shift-request-test-token'])
            ->withHeader('X-CSRF-TOKEN', 'shift-request-test-token')
            ->postJson('/api/shift-requests', $this->payload())->assertCreated();
    }

    public function test_notice_is_optional_but_cannot_exceed_limit(): void
    {
        $this->actingAs($this->admin());
        $payload = $this->payload();
        unset($payload['notice']);
        $response = $this->postJson('/api/shift-requests', $payload)->assertCreated();
        $this->assertDatabaseHas('t_shift_requests', ['id' => $response->json('id'), 'notice' => null]);
        $payload['notice'] = str_repeat('あ', 5001);
        $this->postJson('/api/shift-requests', $payload)->assertUnprocessable()->assertJsonValidationErrors('notice');
        $this->assertDatabaseCount('t_shift_requests', 1);
    }
}
