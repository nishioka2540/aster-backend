<?php

namespace App\Application\ShiftRequest;

use App\Jobs\SendLineMessage;
use App\Models\User;
use Enum\LineNotificationKind;
use Enum\ShiftStatus;
use Enum\UserType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LineShiftNotifier
{
    /** Must be called within the same database transaction as the saved change. */
    public function enqueue(int $requestId, LineNotificationKind $kind, ?string $from = null, ?string $to = null): string
    {
        if (! config('services.line.messaging_token')) {
            return 'LINE通知は未設定のため送信されません。';
        }
        $request = DB::table('t_shift_requests')->where('id', $requestId)->whereNull('deleted_at')->first();
        abort_unless($request, 404);
        $casts = User::whereIn('id', \App\Services\StoreAccess::castIds((int) $request->store_id));
        if ($kind === LineNotificationKind::Reminder) {
            $casts->whereNotExists(fn ($q) => $q->selectRaw('1')->from('t_shifts')
                ->whereColumn('t_shifts.user_id', 'mst_users.id')->where('shift_request_id', $requestId)
                ->whereNull('t_shifts.deleted_at')->where('status', ShiftStatus::Submitted->value));
        }
        $users = $casts->get();
        $title = $kind->title();
        $text = $title."\n対象期間：".($from ?? $request->period_start).' 〜 '.($to ?? $request->period_end);
        if ($kind !== LineNotificationKind::Confirmed) {
            $text .= "\n提出期限：".$request->submission_deadline;
            if ($request->notice) {
                $text .= "\n".Str::limit($request->notice, 2000);
            }
        }
        if ($kind === LineNotificationKind::Reminder) {
            $fromLabel = \Carbon\CarbonImmutable::parse($request->period_start)->format('n月j日');
            $toLabel = \Carbon\CarbonImmutable::parse($request->period_end)->format('n月j日');
            $text = "シフトの提出がまだ確認できていません。\n\n対象期間：{$fromLabel}〜{$toLabel}\n\n提出期限を過ぎていますので、お早めに提出をお願いします。\n\n▼シフトの提出はこちら";
        }
        $text .= "\n".rtrim(config('services.line.frontend_url'), '/');
        $queued = 0;
        foreach ($users as $user) {
            if (! $user->line_id) {
                continue;
            }
            // Explicit database queue keeps the saved change and its notifications atomic.
            $job = (new SendLineMessage($user->id, $text, (string) Str::uuid(), (int) $request->store_id))
                ->onConnection('line-database')->onQueue('line-notifications')->beforeCommit();
            app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch($job);
            $queued++;
        }

        return "LINE通知を{$queued}人分受け付けました。".($users->count() > $queued ? 'LINE未連携のキャストは対象外です。' : '');
    }
}
