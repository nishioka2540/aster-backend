<?php

namespace App\Infrastructure\Query;

use App\Application\ShiftRequest\ShiftRequestAvailabilityQueryService;
use App\Domain\ShiftRequest\ShiftRequestStatus;
use Carbon\CarbonImmutable;
use Enum\ShiftStatus;
use Illuminate\Support\Facades\DB;

final class SqlShiftRequestAvailabilityQueryService implements ShiftRequestAvailabilityQueryService
{
    public function findAcceptingRequests(?int $userId = null): array
    {
        return $this->acceptingRequests(userId: $userId);
    }

    public function isAccepting(int $requestId, ?int $userId = null): bool
    {
        return $this->acceptingRequests($requestId, $userId) !== [];
    }

    /** @return list<object> */
    private function acceptingRequests(?int $requestId = null, ?int $userId = null): array
    {
        return DB::select(<<<'SQL'
            SELECT tsr.id, tsr.period_start, tsr.period_end, tsr.submission_deadline, tsr.notice
            FROM t_shift_requests tsr
            WHERE tsr.deleted_at IS NULL AND tsr.store_id = :store_id
                AND tsr.status = :unconfirmed_status
                AND (tsr.submission_deadline >= :today OR (
                    :has_user = 1 AND NOT EXISTS (
                        SELECT 1 FROM t_shifts shifts
                        WHERE shifts.shift_request_id = tsr.id
                            AND shifts.user_id = :user_id
                            AND shifts.status = :submitted_status
                            AND shifts.deleted_at IS NULL
                    )
                ))
                AND (:all_requests = 1 OR tsr.id = :request_id)
            ORDER BY tsr.period_start, tsr.id
            SQL, [
            'store_id' => \App\Services\StoreAccess::storeId(), 'unconfirmed_status' => ShiftRequestStatus::Unconfirmed->value,
            'submitted_status' => ShiftStatus::Submitted->value,
            'today' => CarbonImmutable::today('Asia/Tokyo')->format('Y-m-d H:i:s'),
            'all_requests' => $requestId === null ? 1 : 0,
            'request_id' => $requestId,
            'has_user' => $userId !== null ? 1 : 0,
            'user_id' => $userId,
        ]);
    }

    public function latestConfirmedEnd(): ?string
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT MAX(request.period_end) AS confirmed_end
            FROM t_shift_requests request
            WHERE request.store_id = :store_id AND request.status = :confirmed_status
                AND request.deleted_at IS NULL
            SQL, ['store_id' => \App\Services\StoreAccess::storeId(), 'confirmed_status' => ShiftRequestStatus::Confirmed->value]);

        return $row?->confirmed_end;
    }
}
