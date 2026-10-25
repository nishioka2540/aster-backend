<?php

namespace App\Application\Query;

use Enum\ShiftScheduleDayStatus;
use Enum\ShiftStatus;
use Enum\UserType;
use Illuminate\Support\Facades\DB;

/** 表示用データの取得・整形を担当する参照Service。 */
final class AdminCastShiftQueryService
{
    public function find(int $requestId, string $castId): array
    {
        $cast = DB::table('mst_users')->whereIn('id', \App\Services\StoreAccess::castIds())->where('ulid', $castId)->first(['id', 'ulid', 'name']);
        abort_unless($cast, 404);
        $period = DB::selectOne(<<<'SQL'
            SELECT * FROM t_shift_requests
            WHERE id = :request_id AND deleted_at IS NULL
            LIMIT 1
            SQL, ['request_id' => $requestId]);
        abort_unless($period, 404);
        $period->finalized_dates = array_column(DB::select(<<<'SQL'
            SELECT work_date FROM t_shift_schedule_days
            WHERE shift_request_id = :request_id AND status = :finalized_status AND deleted_at IS NULL
            SQL, ['request_id' => $requestId, 'finalized_status' => ShiftScheduleDayStatus::Finalized->value]), 'work_date');
        $shift = DB::selectOne(<<<'SQL'
            SELECT * FROM t_shifts
            WHERE shift_request_id = :request_id AND user_id = :user_id
                AND status = :submitted_status AND deleted_at IS NULL
            LIMIT 1
            SQL, ['request_id' => $requestId, 'user_id' => $cast->id, 'submitted_status' => ShiftStatus::Submitted->value]);
        $days = $shift ? collect(DB::select(<<<'SQL'
            SELECT * FROM t_shift_details
            WHERE shift_id = :shift_id AND deleted_at IS NULL
            ORDER BY work_date
            SQL, ['shift_id' => $shift->id]))->map(fn ($day) => [
            'work_date' => $day->work_date,
            'work' => (bool) $day->availability,
            'start_time' => $day->start_time ? substr($day->start_time, 0, 5) : null,
            'end_time' => $day->end_time ? substr($day->end_time, 0, 5) : null,
            'has_reservation' => (bool) $day->has_reservation,
            'note' => $day->note ?? '',
        ])->all() : [];

        return ['cast' => ['id' => $cast->ulid, 'name' => $cast->name],
            'request' => $period, 'submitted' => $shift && (int) $shift->status === ShiftStatus::Submitted->value, 'days' => $days];
    }
}
