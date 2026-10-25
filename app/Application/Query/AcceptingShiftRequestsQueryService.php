<?php

namespace App\Application\Query;

use App\Application\ShiftRequest\ShiftRequestAvailabilityQueryService;
use App\Domain\ShiftRequest\ShiftRequestStatus;
use Carbon\CarbonImmutable;
use Enum\ShiftScheduleDayStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** 表示用データの取得・整形を担当する参照Service。 */
final class AcceptingShiftRequestsQueryService
{
    public function __construct(private readonly ShiftRequestAvailabilityQueryService $availability) {}

    public function forUser(int $userId, bool $isManager, bool $upcomingOnly, bool $includeConfirmed): array|Collection
    {
        if ($isManager) {
            return collect(DB::select(<<<'SQL'
                SELECT id, period_start, period_end, submission_deadline, notice
                FROM t_shift_requests
                WHERE deleted_at IS NULL AND store_id = :store_id
                    AND (:include_past = 1 OR period_end >= :today)
                    AND (:include_confirmed = 1 OR status = :unconfirmed_status)
                ORDER BY status, period_start DESC
                SQL, [
                'store_id' => \App\Services\StoreAccess::storeId(), 'include_past' => $upcomingOnly ? 0 : 1,
                'today' => CarbonImmutable::today('Asia/Tokyo')->toDateString(),
                'include_confirmed' => $includeConfirmed ? 1 : 0,
                'unconfirmed_status' => ShiftRequestStatus::Unconfirmed->value,
            ]));
        }

        return array_map(function (object $request) {
            $dates = DB::select(<<<'SQL'
                SELECT work_date
                FROM t_shift_schedule_days
                WHERE shift_request_id = :request_id
                    AND status = :finalized_status
                    AND deleted_at IS NULL
                SQL, ['request_id' => $request->id, 'finalized_status' => ShiftScheduleDayStatus::Finalized->value]);

            return [
                'id' => (int) $request->id,
                'period_start' => $request->period_start,
                'period_end' => $request->period_end,
                'submission_deadline' => $request->submission_deadline,
                'notice' => $request->notice,
                'finalized_dates' => array_column($dates, 'work_date'),
            ];
        }, $this->availability->findAcceptingRequests($userId));
    }
}
