<?php

namespace App\Infrastructure\Query;

use App\Application\ShiftRequest\ShiftRequestAvailabilityQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SqlShiftRequestAvailabilityQueryService implements ShiftRequestAvailabilityQueryService
{
    public function findAcceptingRequests(): array
    {
        return $this->acceptingRequests();
    }

    public function isAccepting(int $requestId): bool
    {
        return $this->acceptingRequests($requestId) !== [];
    }

    /** @return list<object> */
    private function acceptingRequests(?int $requestId = null): array
    {
        return DB::select(<<<"SQL"
            SELECT tsr.id, tsr.period_start, tsr.period_end, tsr.submission_deadline, tsr.notice
            FROM t_shift_requests tsr
            WHERE tsr.deleted_at IS NULL
                AND tsr.status = 0
                AND tsr.submission_deadline >= :today
                AND (:all_requests = 1 OR tsr.id = :request_id)
                AND tsr.status = 0
            ORDER BY tsr.period_start, tsr.id
            SQL, [
            'today' => CarbonImmutable::today('Asia/Tokyo')->format('Y-m-d H:i:s'),
            'all_requests' => $requestId === null ? 1 : 0,
            'request_id' => $requestId,
        ]);
    }

    public function latestConfirmedEnd(): ?string
    {
        $row = DB::selectOne(<<<"SQL"
            SELECT MAX(request.period_end) AS confirmed_end
            FROM t_shift_requests request
            WHERE request.status = 1
                AND request.deleted_at IS NULL
            SQL);

        return $row?->confirmed_end;
    }
}
