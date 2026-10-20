<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\ShiftRequest\ShiftRequest;
use App\Domain\ShiftRequest\ShiftRequestRepository;
use App\Domain\ShiftRequest\RequestedShiftPeriod;
use Illuminate\Support\Facades\DB;
use App\Application\ShiftRequest\ShiftRequestAvailabilityQueryService;

final class EloquentShiftRequestRepository implements ShiftRequestRepository
{
    public function __construct(private readonly ShiftRequestAvailabilityQueryService $availability)
    {
    }

    public function findAcceptingRequests(?int $userId = null): array
    {
        $rows = $this->availability->findAcceptingRequests($userId);

        return array_map(
            fn (object $row) => new RequestedShiftPeriod(
                (int) $row->id,
                $row->period_start,
                $row->period_end,
                $row->submission_deadline,
                $row->notice,
            ),
            $rows,
        );
    }

    public function save(ShiftRequest $shiftRequest): int
    {
        return DB::transaction(function () use ($shiftRequest) {
            // 全管理者で共通のロックを取り、依頼が0件の場合も同時作成を防ぐ。
            DB::table('mst_users')->where(fn ($query) => $query->where('auth_type', 1)->orWhere(fn ($staff) => $staff->where('user_type', 1)->whereIn('role_type', [1, 2])))->orderBy('id')->lockForUpdate()->get();
            ShiftRequest::assertCanCreate(DB::table('t_shift_requests')->whereNull('deleted_at')->where('status', 0)->exists());
            $model = ShiftRequestModel::create([
                'requested_by' => $shiftRequest->requestedBy,
                'period_start' => $shiftRequest->periodStart->format('Y-m-d'),
                'period_end' => $shiftRequest->periodEnd->format('Y-m-d'),
                'submission_deadline' => $shiftRequest->submissionDeadline->format('Y-m-d H:i:s'),
                'notice' => $shiftRequest->notice,
                'status' => $shiftRequest->status->value,
            ]);

            return (int) $model->getKey();
        });
    }
}
