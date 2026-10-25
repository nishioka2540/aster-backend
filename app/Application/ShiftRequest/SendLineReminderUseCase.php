<?php

namespace App\Application\ShiftRequest;

use App\Domain\ShiftRequest\ShiftRequestStatus;
use Enum\LineNotificationKind;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** 再通知の対象・頻度を確認し、通知をキューに登録する。 */
final readonly class SendLineReminderUseCase
{
    public function __construct(private LineShiftNotifier $notifier) {}

    public function execute(int $requestId): string
    {
        return Cache::lock('line-reminder:'.$requestId, 30)->get(function () use ($requestId) {
            abort_if(Cache::has('line-reminder-sent:'.$requestId), 429, '再通知は5分後にお試しください。');
            $message = DB::transaction(function () use ($requestId) {
                $period = DB::table('t_shift_requests')->where('id', $requestId)->whereNull('deleted_at')->lockForUpdate()->first();
                abort_unless($period, 404);
                abort_if((int) $period->status !== ShiftRequestStatus::Unconfirmed->value || $period->submission_deadline >= now('Asia/Tokyo')->startOfDay()->format('Y-m-d H:i:s'), 422, '提出期限を過ぎた未確定の依頼のみ通知できます。');

                return $this->notifier->enqueue($requestId, LineNotificationKind::Reminder);
            });
            Cache::put('line-reminder-sent:'.$requestId, true, 300);

            return $message;
        }, fn () => abort(429, '通知を処理中です。'));
    }
}
