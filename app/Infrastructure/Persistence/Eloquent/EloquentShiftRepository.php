<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Shift\Shift;
use App\Domain\Shift\ShiftRepository;
use Enum\ShiftStatus;
use Illuminate\Support\Facades\DB;
use App\Application\ShiftRequest\ShiftRequestAvailabilityQueryService;

final class EloquentShiftRepository implements ShiftRepository
{
    public function __construct(private readonly ShiftRequestAvailabilityQueryService $availability)
    {
    }

    public function transaction(callable $operation): int
    {
        return DB::transaction($operation);
    }

    public function findRequestForUpdate(int $requestId, int $userId): ?array
    {
        $request = DB::table('t_shift_requests')->where('id', $requestId)->whereNull('deleted_at')->lockForUpdate()->first();

        return $request ? [
            'period_start' => $request->period_start,
            'period_end' => $request->period_end,
            'submission_deadline' => $request->submission_deadline,
            'open' => (int) $request->status === 0,
            'finalized_dates' => DB::table('t_shift_schedule_days')->where('shift_request_id', $requestId)->where('status', 2)->whereNull('deleted_at')->pluck('work_date')->all(),
            'accepting' => $this->availability->isAccepting($requestId, $userId),
        ] : null;
    }

    public function save(Shift $shift): int
    {
        $requestId = $shift->requestId;
        $userId = $shift->userId;
        $now = $shift->submittedAt->format('Y-m-d H:i:s');
        $existing = DB::table('t_shifts')->where('shift_request_id', $requestId)->where('user_id', $userId)->first();
        $attributes = ['status' => $shift->status->value, 'submitted_at' => $shift->status === ShiftStatus::Submitted ? $now : null, 'updated_at' => $now, 'deleted_at' => null];
        if ($existing && (int) $existing->status === ShiftStatus::Submitted->value && $shift->status === ShiftStatus::Draft) {
            throw new \DomainException('提出済みのシフトは下書きに戻せません。変更内容を再提出してください。');
        }
        if ($existing) {
            $id = (int) $existing->id;
            DB::table('t_shifts')->where('id', $id)->update($attributes);
        } else {
            $id = DB::table('t_shifts')->insertGetId([...$attributes, 'shift_request_id' => $requestId, 'user_id' => $userId, 'created_at' => $now]);
        }
        $finalized = DB::table('t_shift_schedule_days')->where('shift_request_id', $requestId)->where('status', 2)->whereNull('deleted_at')->pluck('work_date')->all();
        DB::table('t_shift_details')->where('shift_id', $id)->whereNotIn('work_date', $finalized)
            ->whereNotIn('work_date', array_column($shift->days, 'work_date'))->update(['deleted_at' => $now, 'updated_at' => $now]);
        foreach ($shift->days as $day) {
            $work = (bool) $day['work'];
            DB::table('t_shift_details')->updateOrInsert(['shift_id' => $id, 'work_date' => $day['work_date']], [
                'availability' => $work ? 1 : 0,
                'start_time' => $work ? ($day['start_time'] ?? null) : null,
                'end_time' => $work ? ($day['end_time'] ?? null) : null,
                'ends_next_day' => $work && ($day['end_time'] ?? null) === '00:00',
                'has_reservation' => $work && $day['has_reservation'],
                'has_companion' => false,
                'note' => $work ? ($day['note'] ?? null) : null,
                'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
            ]);
        }

        return $id;
    }
}
