<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Shift\CastShiftSubmission;
use App\Domain\Shift\ShiftStatusRepository;
use App\Domain\Shift\ShiftSubmissionPeriod;
use App\Domain\ShiftRequest\ShiftRequestStatus;
use Enum\ShiftStatus;
use Enum\UserType;
use Illuminate\Support\Facades\DB;

final class EloquentShiftStatusRepository implements ShiftStatusRepository
{
    public function findAcceptingPeriods(): array
    {
        $periods = DB::table('t_shift_requests')->where('store_id', \App\Services\StoreAccess::storeId())->whereNull('deleted_at')->where('status', ShiftRequestStatus::Unconfirmed->value)
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
                AND tsr.status = :unconfirmed_status
                AND tsr.deleted_at IS NULL
            WHERE tsr.store_id = :store_id AND mu.id IN (SELECT membership.user_id FROM t_store_users membership INNER JOIN mst_access_roles access ON access.id = membership.access_role_id WHERE membership.store_id = tsr.store_id AND access.code = :cast_code AND membership.deleted_at IS NULL)
                AND mu.deleted_at IS NULL
            ORDER BY tsr.period_start, tsr.id, mu.name, mu.id
            SQL, [
            'submitted' => ShiftStatus::Submitted->value,
            'unconfirmed_status' => ShiftRequestStatus::Unconfirmed->value,
            'store_id' => \App\Services\StoreAccess::storeId(), 'cast_code' => 'cast',
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
                ON tsr.status = :unconfirmed_status
                AND tsr.deleted_at IS NULL
            LEFT OUTER JOIN t_shifts ts
                ON ts.user_id = mu.id
                AND ts.shift_request_id = tsr.id
                AND ts.deleted_at IS NULL
            WHERE tsr.store_id = :store_id AND mu.id IN (SELECT membership.user_id FROM t_store_users membership INNER JOIN mst_access_roles access ON access.id = membership.access_role_id WHERE membership.store_id = tsr.store_id AND access.code = :cast_code AND membership.deleted_at IS NULL)
                AND mu.deleted_at IS NULL
                AND (ts.id IS NULL OR ts.status <> :submitted)
            ORDER BY tsr.period_start, tsr.id, mu.name, mu.id
            SQL, [
            'not_started' => ShiftStatus::NotStarted->value,
            'unconfirmed_status' => ShiftRequestStatus::Unconfirmed->value,
            'store_id' => \App\Services\StoreAccess::storeId(), 'cast_code' => 'cast',
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
