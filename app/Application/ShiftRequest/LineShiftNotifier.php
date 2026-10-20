<?php

namespace App\Application\ShiftRequest;

use App\Jobs\SendLineMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LineShiftNotifier
{
    /** Must be called within the same database transaction as the saved change. */
    public function enqueue(int $requestId, string $kind, ?string $from = null, ?string $to = null): string
    {
        if (! config('services.line.messaging_token')) {
            return 'LINE通知は未設定のため送信されません。';
        }
        $request = DB::table('t_shift_requests')->where('id', $requestId)->whereNull('deleted_at')->first();
        abort_unless($request, 404);
        $casts = User::where('user_type', User::TYPE_CAST);
        if ($kind === 'reminder') {
            $casts->whereNotExists(fn ($q) => $q->selectRaw('1')->from('t_shifts')
                ->whereColumn('t_shifts.user_id', 'mst_users.id')->where('shift_request_id', $requestId)
                ->whereNull('t_shifts.deleted_at')->where('status', 2));
        }
        $users = $casts->get();
        $title = match ($kind) {
            'created' => 'シフト提出のお願い', 'updated' => 'シフト提出依頼が変更されました',
            'reminder' => 'シフト提出のリマインド', 'confirmed' => 'シフトが確定しました',
        };
        $text = $title."\n対象期間：".($from ?? $request->period_start).' 〜 '.($to ?? $request->period_end);
        if ($kind !== 'confirmed') {
            $text .= "\n提出期限：".$request->submission_deadline;
            if ($request->notice) {
                $text .= "\n".Str::limit($request->notice, 2000);
            }
        }
        if ($kind === 'reminder') {
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
            $job = (new SendLineMessage($user->id, $text, (string) Str::uuid()))
                ->onConnection('line-database')->onQueue('line-notifications')->beforeCommit();
            app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch($job);
            $queued++;
        }

        return "LINE通知を{$queued}人分受け付けました。".($users->count() > $queued ? 'LINE未連携のキャストは対象外です。' : '');
    }
}
