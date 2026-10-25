<?php

namespace App\Application\Query;

use App\Domain\Shift\CastShiftSubmission;
use App\Domain\Shift\ShiftStatusRepository;
use App\Domain\Shift\ShiftSubmissionPeriod;
use Enum\UserType;
use Illuminate\Support\Facades\DB;

/** 表示用データの取得・整形を担当する参照Service。 */
final class ShiftStatusQueryService
{
    public function __construct(private readonly ShiftStatusRepository $repository) {}

    public function all(): array
    {
        $ulids = DB::table('mst_users')->whereIn('id', \App\Services\StoreAccess::castIds())->pluck('ulid', 'id');
        $users = fn (array $casts) => array_map(
            fn (CastShiftSubmission $cast) => ['id' => $ulids[$cast->userId], 'name' => $cast->name],
            $casts,
        );

        return array_map(
            fn (ShiftSubmissionPeriod $period) => [
                'id' => $period->requestId,
                'period_start' => $period->periodStart,
                'period_end' => $period->periodEnd,
                'submission_deadline' => $period->submissionDeadline,
                'reminder_available' => $period->submissionDeadline < now('Asia/Tokyo')->startOfDay()->format('Y-m-d H:i:s'),
                'submitted' => $users($period->submitted()),
                'unsubmitted' => $users($period->unsubmitted()),
            ],
            $this->repository->findAcceptingPeriods(),
        );
    }
}
