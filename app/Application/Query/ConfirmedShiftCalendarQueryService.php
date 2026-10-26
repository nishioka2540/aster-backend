<?php

namespace App\Application\Query;

use Carbon\CarbonImmutable;
use Enum\ShiftScheduleDayStatus;
use Enum\UserType;
use Illuminate\Support\Facades\DB;

/** 表示用データの取得・整形を担当する参照Service。 */
final class ConfirmedShiftCalendarQueryService
{
    public function between(CarbonImmutable $from, CarbonImmutable $to, bool $includeRequestIds): array
    {
        // Publish finalized days even when the rest of the request is still open.
        $bindings = [
            'store_id' => \App\Services\StoreAccess::storeId(), 'finalized_status' => ShiftScheduleDayStatus::Finalized->value,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
        ];
        $days = collect(DB::select(<<<'SQL'
            SELECT day.id, day.work_date, day.shift_request_id, day.required_count
            FROM t_shift_schedule_days day
            INNER JOIN t_shift_requests request ON request.id = day.shift_request_id
            WHERE request.store_id = :store_id AND day.status = :finalized_status
                AND day.deleted_at IS NULL AND request.deleted_at IS NULL
                AND day.work_date BETWEEN :from_date AND :to_date
            SQL, $bindings));
        $casts = collect(DB::select(<<<'SQL'
            SELECT assignment.shift_schedule_day_id, member.ulid, member.name,
                assignment.start_time, assignment.end_time, assignment.ends_next_day
            FROM t_shift_assignments assignment
            INNER JOIN mst_users member ON member.id = assignment.user_id
            INNER JOIN t_shift_schedule_days day ON day.id = assignment.shift_schedule_day_id
            INNER JOIN t_shift_requests request ON request.id = day.shift_request_id
            WHERE request.store_id = :store_id AND day.status = :finalized_status
                AND day.work_date BETWEEN :from_date AND :to_date
                AND day.deleted_at IS NULL AND request.deleted_at IS NULL
                AND assignment.work = :working AND assignment.deleted_at IS NULL
                AND member.deleted_at IS NULL AND member.id IN (SELECT membership.user_id FROM t_store_users membership INNER JOIN mst_access_roles access ON access.id = membership.access_role_id WHERE membership.store_id = request.store_id AND access.code = :cast_code AND membership.deleted_at IS NULL)
            ORDER BY assignment.start_time, member.id
            SQL, [...$bindings, 'working' => 1, 'cast_code' => 'cast']))->groupBy('shift_schedule_day_id');
        $byDate = $days->groupBy('work_date');
        $result = [];
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $dateDays = $byDate->get($date->toDateString(), collect());
            $members = $dateDays->flatMap(fn ($day) => $casts->get($day->id, collect()))
                ->unique('ulid')->sortBy('start_time')->values()->map(fn ($cast) => [
                    'id' => $cast->ulid, 'name' => $cast->name,
                    'start' => substr($cast->start_time, 0, 5),
                    'end' => $cast->ends_next_day ? '24:00' : substr($cast->end_time, 0, 5),
                ])->all();
            $result[] = ['date' => $date->toDateString(), 'confirmed' => $dateDays->isNotEmpty(),
                'count' => count($members), 'required_count' => (int) $dateDays->sum('required_count'), 'casts' => $members,
                ...($includeRequestIds ? ['request_ids' => $dateDays->pluck('shift_request_id')->unique()->values()->all()] : [])];
        }

        return $result;
    }
}
