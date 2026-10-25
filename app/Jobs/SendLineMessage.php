<?php

namespace App\Jobs;

use App\Models\User;
use Enum\UserType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

final class SendLineMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 25;

    public int $createdAt;

    public function __construct(public int $userId, public string $text, public string $retryKey, public ?int $storeId = null)
    {
        $this->createdAt = time();
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        if (time() - $this->createdAt >= 23 * 3600) {
            $this->fail(new \RuntimeException('LINE notification expired; retry key must not be reused after 24 hours.'));

            return;
        }
        $user = User::find($this->userId);
        if ($this->storeId === null || ! in_array($this->userId, \App\Services\StoreAccess::castIds($this->storeId), true)) return;
        if (! $user?->line_id) {
            return;
        }
        $token = config('services.line.messaging_token');
        if (! $token) {
            throw new \RuntimeException('LINE Messaging API token is not configured.');
        }

        $response = Http::withToken($token)->withHeaders(['X-Line-Retry-Key' => $this->retryKey])
            ->connectTimeout(5)->timeout(20)->post('https://api.line.me/v2/bot/message/push', [
                'to' => $user->line_id,
                'messages' => [['type' => 'text', 'text' => $this->text]],
            ]);
        if ($response->successful() || ($response->status() === 409 && $response->header('x-line-accepted-request-id'))) {
            return;
        }
        // Never include access tokens, recipient IDs or message bodies in exception logs.
        $error = new \RuntimeException('LINE Messaging API returned HTTP '.$response->status());
        if ($response->status() === 429 || $response->serverError()) {
            throw $error;
        }
        $this->fail($error);
    }
}
