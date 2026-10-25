<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\ShiftRequest\ShiftRequestAvailabilityQueryService;
use App\Domain\ShiftRequest\RequestedShiftPeriod;
use App\Domain\ShiftRequest\ShiftRequest;
use App\Domain\ShiftRequest\ShiftRequestRepository;
use App\Domain\ShiftRequest\ShiftRequestStatus;
use Enum\ShiftScheduleDayStatus;
use Enum\ShiftStatus;
use Illuminate\Support\Facades\DB;

final class EloquentShiftRequestRepository implements ShiftRequestRepository
{
    public function __construct(private readonly ShiftRequestAvailabilityQueryService $availability) {}

    public function transaction(callable $operation): mixed
    {
        return DB::transaction($operation);
    }

    public function findForUpdate(int $id): ?ShiftRequest
    {
        $row = DB::table('t_shift_requests')->where('store_id', \App\Services\StoreAccess::storeId())->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
        if (! $row) {
            return null;
        }
        $timezone = new \DateTimeZone('Asia/Tokyo');

        return new ShiftRequest((int) $row->requested_by,
            new \DateTimeImmutable($row->period_start, $timezone),
            new \DateTimeImmutable($row->period_end, $timezone),
            new \DateTimeImmutable($row->submission_deadline, $timezone),
            $row->notice, ShiftRequestStatus::from((int) $row->status));
    }

    public function update(int $id, ShiftRequest $request, bool $periodChanged): void
    {
        $now = now();
        if ($periodChanged) {
            // Keep the cast's inputs, but require resubmission for the changed period.
            DB::table('t_shifts')->where('shift_request_id', $id)->whereNull('deleted_at')
                ->update(['status' => ShiftStatus::Draft->value, 'submitted_at' => null, 'updated_at' => $now]);
            $dayIds = DB::table('t_shift_schedule_days')->where('shift_request_id', $id)->pluck('id');
            DB::table('t_shift_assignments')->whereIn('shift_schedule_day_id', $dayIds)
                ->update(['deleted_at' => $now, 'updated_at' => $now]);
            DB::table('t_shift_schedule_days')->where('shift_request_id', $id)
                ->update(['status' => ShiftScheduleDayStatus::NotDecided->value, 'decided_by' => null, 'decided_at' => null,
                    'confirmed_by' => null, 'confirmed_at' => null, 'deleted_at' => $now, 'updated_at' => $now]);
        }
        DB::table('t_shift_requests')->where('id', $id)->update([
            'period_start' => $request->periodStart->format('Y-m-d'), 'period_end' => $request->periodEnd->format('Y-m-d'),
            'submission_deadline' => $request->submissionDeadline->format('Y-m-d H:i:s'), 'notice' => $request->notice,
            'updated_at' => $now,
        ]);

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
            $storeId = \App\Services\StoreAccess::storeId();
            abort_unless($storeId, 403);
            DB::table('t_store')->where('id', $storeId)->lockForUpdate()->first();
            ShiftRequest::assertCanCreate(DB::table('t_shift_requests')->where('store_id', $storeId)->whereNull('deleted_at')->where('status', ShiftRequestStatus::Unconfirmed->value)->exists());
            $model = ShiftRequestModel::create([
                'store_id' => $storeId, 'requested_by' => $shiftRequest->requestedBy,
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
