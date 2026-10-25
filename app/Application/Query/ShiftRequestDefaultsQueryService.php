<?php

namespace App\Application\Query;

use App\Application\ShiftRequest\ShiftRequestAvailabilityQueryService;
use App\Domain\ShiftRequest\ShiftRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** 表示用データの取得・整形を担当する参照Service。 */
final class ShiftRequestDefaultsQueryService
{
    public function __construct(private readonly ShiftRequestAvailabilityQueryService $availability) {}

    public function get(): array
    {
        $end = $this->availability->latestConfirmedEnd();
        $start = $end ? CarbonImmutable::parse($end, 'Asia/Tokyo')->addWeekday()->toDateString() : null;
        $deadline = $end ? null : CarbonImmutable::today('Asia/Tokyo')->addWeekday()->toDateString();

        $unconfirmed = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM t_shift_requests
                WHERE deleted_at IS NULL AND store_id = :store_id AND status = :unconfirmed_status
            ) AS has_unconfirmed_request
            SQL, ['store_id' => \App\Services\StoreAccess::storeId(), 'unconfirmed_status' => ShiftRequestStatus::Unconfirmed->value]);

        return [
            'period_start' => $start,
            'submission_deadline' => $deadline,
            'confirmed_end' => $end,
            'has_unconfirmed_request' => (bool) $unconfirmed->has_unconfirmed_request,
        ];
    }
}
