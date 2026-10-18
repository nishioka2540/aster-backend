<?php

namespace Tests\Feature;

use App\Jobs\SendLineMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LineNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function createRequest(): int
    {
        return $this->actingAs($this->admin)->postJson('/api/shift-requests', [
            'period_start' => '2026-10-26', 'period_end' => '2026-10-30',
            'submission_deadline' => '2026-10-23 23:59:59', 'notice' => 'お願いします',
        ])->assertCreated()->json('id');
    }

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.line.messaging_token' => 'test-token']);
        $this->travelTo(now()->setDate(2026, 10, 19));
        Cache::flush();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['auth_type' => 1])->save();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_creation_and_update_notify_only_active_linked_casts(): void
    {
        $cast = User::factory()->create(['line_id' => 'Ucast']);
        User::factory()->create();
        User::factory()->create(['line_id' => 'Udeleted'])->delete();
        $id = $this->createRequest();
        Queue::assertPushed(SendLineMessage::class, fn ($job) => $job->userId === $cast->id && str_contains($job->text, 'シフト提出のお願い'));
        Queue::assertPushed(SendLineMessage::class, 1);
        $this->putJson('/api/shift-requests/'.$id, [
            'period_start' => '2026-10-26', 'period_end' => '2026-10-30',
            'submission_deadline' => '2026-10-23 23:59:59', 'notice' => '変更',
        ])->assertOk();
        Queue::assertPushed(SendLineMessage::class, 2);
    }

    public function test_reminder_excludes_submitted_cast_and_rejects_repeat_and_cast(): void
    {
        $submitted = User::factory()->create(['line_id' => 'Usubmitted']);
        $unsubmitted = User::factory()->create(['line_id' => 'Uunsubmitted']);
        $id = $this->createRequest();
        DB::table('t_shifts')->insert(['user_id' => $submitted->id, 'shift_request_id' => $id, 'status' => 2, 'created_at' => now(), 'updated_at' => now()]);
        Queue::fake();
        $url = '/api/admin/shift-requests/'.$id.'/remind';
        $this->postJson($url)->assertOk();
        Queue::assertPushed(SendLineMessage::class, fn ($job) => $job->userId === $unsubmitted->id);
        Queue::assertPushed(SendLineMessage::class, 1);
        $this->postJson($url)->assertStatus(429);
        $this->actingAs($unsubmitted)->postJson($url)->assertForbidden();
    }

    public function test_job_sends_with_stable_retry_key_and_retries_server_errors(): void
    {
        $cast = User::factory()->create(['line_id' => 'Ucast']);
        Http::fake(['api.line.me/*' => Http::sequence()->push([], 500)->push([], 200)]);
        $job = new SendLineMessage($cast->id, '通知', '123e4567-e89b-12d3-a456-426614174000');
        try {
            $job->handle();
            $this->fail('Expected retryable failure');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('500', $exception->getMessage());
        }
        $job->handle();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Line-Retry-Key', $job->retryKey) && $request['to'] === 'Ucast');
    }

    public function test_partial_confirmation_respects_notification_option(): void
    {
        User::factory()->create(['line_id' => 'Ucast']);
        $id = $this->createRequest();
        Queue::fake();
        $url = '/api/admin/shift-requests/'.$id.'/schedule';
        // No submitted availability: days can be decided with no workers.
        foreach (['2026-10-26', '2026-10-27'] as $date) {
            $this->putJson($url.'/days/'.$date, ['casts' => []])->assertOk();
        }
        $this->postJson($url.'/confirm', ['from' => '2026-10-26', 'to' => '2026-10-26', 'notify_line' => false])->assertOk();
        Queue::assertNothingPushed();
        $this->postJson($url.'/confirm', ['from' => '2026-10-27', 'to' => '2026-10-27', 'notify_line' => true])->assertOk();
        Queue::assertPushed(SendLineMessage::class, fn ($job) => str_contains($job->text, '2026-10-27 〜 2026-10-27'));
        Queue::assertPushed(SendLineMessage::class, 1);
    }

    public function test_database_queue_persists_job_in_the_application_database(): void
    {
        // Restore the real queue manager after Queue::fake().
        app()->forgetInstance('queue');
        Queue::clearResolvedInstance('queue');
        User::factory()->create(['line_id' => 'Ucast']);
        $this->createRequest();
        $this->assertDatabaseHas('jobs', ['queue' => 'line-notifications']);
    }

    public function test_queue_failure_rolls_back_the_saved_request(): void
    {
        User::factory()->create(['line_id' => 'Ucast']);
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('queue unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->createRequest();
            $this->fail('Expected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('queue unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('t_shift_requests', 0);
    }
}
