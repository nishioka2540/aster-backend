<?php

namespace App\Application\Query;

use Illuminate\Support\Facades\DB;

/** 表示用データの取得・整形を担当する参照Service。 */
final class SavedShiftQueryService
{
    public function forUser(int $userId): \Illuminate\Support\Collection
    {
        $shifts = collect(DB::select(<<<'SQL'
            SELECT * FROM t_shifts
            WHERE user_id = :user_id AND deleted_at IS NULL
            SQL, ['user_id' => $userId]));
        $requests = collect(DB::select(<<<'SQL'
            SELECT request.* FROM t_shift_requests request
            WHERE request.deleted_at IS NULL AND request.store_id = :store_id
                AND EXISTS (
                    SELECT 1 FROM t_shifts shift
                    WHERE shift.shift_request_id = request.id
                        AND shift.user_id = :user_id AND shift.deleted_at IS NULL
                )
            SQL, ['user_id' => $userId, 'store_id' => \App\Services\StoreAccess::storeId()]))->keyBy('id');
        $shifts = $shifts->filter(fn ($shift) => $requests->has($shift->shift_request_id));
        $details = collect(DB::select(<<<'SQL'
            SELECT detail.* FROM t_shift_details detail
            INNER JOIN t_shifts shift ON shift.id = detail.shift_id
            INNER JOIN t_shift_requests request ON request.id = shift.shift_request_id
            WHERE shift.user_id = :user_id AND shift.deleted_at IS NULL
                AND request.deleted_at IS NULL AND request.store_id = :store_id AND detail.deleted_at IS NULL
            ORDER BY detail.work_date
            SQL, ['user_id' => $userId, 'store_id' => \App\Services\StoreAccess::storeId()]))->groupBy('shift_id');

        return $shifts->map(fn ($shift) => [
            'shift_request_id' => (int) $shift->shift_request_id, 'status' => (int) $shift->status, 'submitted_at' => $shift->submitted_at,
            'request' => $requests->get($shift->shift_request_id),
            'days' => ($details->get($shift->id) ?? collect())->map(fn ($day) => [
                'work_date' => $day->work_date, 'work' => (bool) $day->availability,
                'start_time' => $day->start_time ? substr($day->start_time, 0, 5) : null,
                'end_time' => $day->end_time ? substr($day->end_time, 0, 5) : null,
                'has_reservation' => (bool) $day->has_reservation, 'note' => $day->note ?? '',
            ])->values(),
        ])->values();
    }
}
