<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Shift\CastShiftSubmission;
use App\Domain\Shift\ShiftStatusRepository;
use App\Domain\Shift\ShiftSubmissionPeriod;
use Enum\ShiftStatus;
use Illuminate\Support\Facades\DB;

final class EloquentShiftStatusRepository implements ShiftStatusRepository
{
    public function findAcceptingPeriods(): array
    {
        $periods = DB::table('t_shift_requests')->whereNull('deleted_at')->where('status', 0)
            ->orderBy('period_start')->orderBy('id')->get()->all();

        if ($periods === []) {
            return [];
        }

        $submitted = $this->findSubmitted();
        $unsubmitted = $this->findUnsubmitted();

        return array_map(fn (object $period) => new ShiftSubmissionPeriod(
            (int) $period->id, $period->period_start, $period->period_end,
            [...($submitted[$period->id] ?? []), ...($unsubmitted[$period->id] ?? [])],
            $period->submission_deadline,
        ), $periods);
    }

    public function findSubmitted(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT mu.id AS user_id, mu.name, tsr.id AS shift_request_id, ts.status AS shift_status
            FROM mst_users mu
            INNER JOIN t_shifts ts
                ON ts.user_id = mu.id
                AND ts.status = :submitted
                AND ts.deleted_at IS NULL
            INNER JOIN t_shift_requests tsr
                ON tsr.id = ts.shift_request_id
                AND tsr.status = 0
                AND tsr.deleted_at IS NULL
            WHERE mu.user_type = :cast_user_type
                AND mu.deleted_at IS NULL
            ORDER BY tsr.period_start, tsr.id, mu.name, mu.id
            SQL, [
            'submitted' => ShiftStatus::Submitted->value,
            'cast_user_type' => 2,
        ]);

        return $this->groupByRequest($rows);
    }

    public function findUnsubmitted(): array
    {
        // LEFT JOINによりシフト未登録・削除済みのキャストも未提出に含める。
        $rows = DB::select(<<<'SQL'
            SELECT mu.id AS user_id, mu.name, tsr.id AS shift_request_id,
                COALESCE(ts.status, :not_started) AS shift_status
            FROM mst_users mu
            INNER JOIN t_shift_requests tsr
                ON tsr.status = 0
                AND tsr.deleted_at IS NULL
            LEFT OUTER JOIN t_shifts ts
                ON ts.user_id = mu.id
                AND ts.shift_request_id = tsr.id
                AND ts.deleted_at IS NULL
            WHERE mu.user_type = :cast_user_type
                AND mu.deleted_at IS NULL
                AND (ts.id IS NULL OR ts.status <> :submitted)
            ORDER BY tsr.period_start, tsr.id, mu.name, mu.id
            SQL, [
            'not_started' => ShiftStatus::NotStarted->value,
            'cast_user_type' => 2,
            'submitted' => ShiftStatus::Submitted->value,
        ]);

        return $this->groupByRequest($rows);
    }

    /** @param list<object> $rows
     * @return array<int, list<CastShiftSubmission>>
     */
    private function groupByRequest(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->shift_request_id][] = new CastShiftSubmission(
                (int) $row->user_id, $row->name, ShiftStatus::from((int) $row->shift_status),
            );
        }

        return $result;
    }
}
