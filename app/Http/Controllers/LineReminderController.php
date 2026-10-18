<?php

namespace App\Http\Controllers;

use App\Application\ShiftRequest\LineShiftNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class LineReminderController extends Controller
{
    public function __invoke(Request $request, int $requestId, LineShiftNotifier $notifier)
    {
        abort_unless($request->user()->auth_type === 1, 403);

        return Cache::lock('line-reminder:'.$requestId, 30)->get(function () use ($requestId, $notifier) {
            abort_if(Cache::has('line-reminder-sent:'.$requestId), 429, '再通知は5分後にお試しください。');
            $message = DB::transaction(function () use ($requestId, $notifier) {
                $period = DB::table('t_shift_requests')->where('id', $requestId)->whereNull('deleted_at')->lockForUpdate()->first();
                abort_unless($period, 404);
                abort_if((int) $period->status !== 0 || $period->submission_deadline < now('Asia/Tokyo')->startOfDay()->format('Y-m-d H:i:s'), 422, '受付中の依頼のみ通知できます。');

                return $notifier->enqueue($requestId, 'reminder');
            });
            Cache::put('line-reminder-sent:'.$requestId, true, 300);

            return response()->json(['message' => $message]);
        }, fn () => abort(429, '通知を処理中です。'));
    }
}
