<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\ShiftRequest\ShiftRequestStatus;
use App\Domain\ShiftSchedule\ShiftSchedule;
use App\Domain\ShiftSchedule\ShiftScheduleRepository;
use Carbon\CarbonImmutable;
use Enum\ShiftScheduleDayStatus;
use Enum\ShiftStatus;
use Enum\UserType;
use Illuminate\Support\Facades\DB;

final class EloquentShiftScheduleRepository implements ShiftScheduleRepository
{
    public function transaction(callable $operation): mixed
    {
        return DB::transaction($operation);
    }

    public function find(int $requestId, bool $lock = false): ?ShiftSchedule
    {
        $query = DB::table('t_shift_requests')->where('store_id', \App\Services\StoreAccess::storeId())->where('id', $requestId)->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }
        $request = $query->first();
        if (! $request) {
            return null;
        }

        return new ShiftSchedule($requestId, $request->period_start, $request->period_end, (int) $request->status === ShiftRequestStatus::Confirmed->value, $request->submission_deadline);
    }

    public function publishedForCast(int $castId): array
    {
        $periods = DB::table('t_shift_requests as request')->where('request.store_id', \App\Services\StoreAccess::storeId())
            ->whereNull('request.deleted_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('t_shift_schedule_days')->whereColumn('shift_request_id', 'request.id')->where('status', ShiftScheduleDayStatus::Finalized->value)->whereNull('deleted_at'))
            ->orderBy('request.period_start')->orderBy('request.id')->select('request.id', 'request.period_start', 'request.period_end')->get();

        return $periods->map(function ($period) use ($castId) {
            $shifts = DB::table('t_shift_schedule_days as day')->join('t_shift_assignments as assignment', 'assignment.shift_schedule_day_id', '=', 'day.id')
                ->where('day.shift_request_id', $period->id)->where('day.status', ShiftScheduleDayStatus::Finalized->value)->whereNull('day.deleted_at')
                ->where('assignment.user_id', $castId)->where('assignment.work', true)->whereNull('assignment.deleted_at')
                ->orderBy('day.work_date')->select('day.work_date', 'assignment.start_time', 'assignment.end_time', 'assignment.ends_next_day', 'assignment.has_reservation')->get();

            return ['from' => $period->period_start, 'to' => $period->period_end,
                'finalized_dates' => DB::table('t_shift_schedule_days')->where('shift_request_id', $period->id)
                    ->where('status', ShiftScheduleDayStatus::Finalized->value)->whereNull('deleted_at')->orderBy('work_date')->pluck('work_date')->all(),
                'shifts' => $shifts->map(fn ($shift) => [
                    'date' => $shift->work_date, 'start' => substr($shift->start_time, 0, 5),
                    'end' => $shift->ends_next_day ? '24:00' : substr($shift->end_time, 0, 5), 'reserved' => (bool) $shift->has_reservation,
                ])->all()];
        })->all();
    }

    public function castIds(int $requestId, string $date): array
    {
        $finalized = DB::table('t_shift_schedule_days')->where('shift_request_id', $requestId)
            ->where('work_date', $date)->where('status', ShiftScheduleDayStatus::Finalized->value)->whereNull('deleted_at')->value('id');

        return DB::table('mst_users as cast')
            ->whereIn('cast.id', \App\Services\StoreAccess::castIds())->whereNull('cast.deleted_at')
            ->where(function ($eligible) use ($requestId, $date, $finalized) {
                $eligible->whereExists(function ($query) use ($requestId, $date) {
                    $query->selectRaw('1')->from('t_shift_details as detail')
                        ->join('t_shifts as shift', 'shift.id', '=', 'detail.shift_id')
                        ->whereColumn('shift.user_id', 'cast.id')
                        ->where('shift.shift_request_id', $requestId)->where('shift.status', ShiftStatus::Submitted->value)
                        ->where('detail.work_date', $date)->where('detail.availability', true)
                        ->whereNull('shift.deleted_at')->whereNull('detail.deleted_at');
                });
                if ($finalized) {
                    $eligible->orWhereExists(function ($query) use ($finalized) {
                        $query->selectRaw('1')->from('t_shift_assignments as assignment')
                            ->whereColumn('assignment.user_id', 'cast.id')
                            ->where('assignment.shift_schedule_day_id', $finalized)->whereNull('assignment.deleted_at');
                    });
                }
            })->orderBy('cast.id')->pluck('cast.id')->map(fn ($id) => (int) $id)->all();
    }

    public function allCastIds(): array
    {
        return DB::table('mst_users')->whereIn('id', \App\Services\StoreAccess::castIds())->whereNull('deleted_at')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function days(int $requestId): array
    {
        $rows = DB::table('t_shift_schedule_days as day')
            ->where('day.shift_request_id', $requestId)->whereNull('day.deleted_at')
            ->select('day.id', 'day.work_date', 'day.status', 'day.required_count')->get();
        $result = [];
        foreach ($rows as $day) {
            $count = DB::table('t_shift_assignments as assignment')->join('mst_users as cast', 'cast.id', '=', 'assignment.user_id')
                ->where('assignment.shift_schedule_day_id', $day->id)->where('assignment.work', true)
                ->whereNull('assignment.deleted_at')->whereNull('cast.deleted_at')->whereIn('cast.id', \App\Services\StoreAccess::castIds())->count();
            $savedIds = DB::table('t_shift_assignments as assignment')->join('mst_users as cast', 'cast.id', '=', 'assignment.user_id')
                ->where('assignment.shift_schedule_day_id', $day->id)->whereNull('assignment.deleted_at')
                ->whereNull('cast.deleted_at')->whereIn('cast.id', \App\Services\StoreAccess::castIds())->orderBy('cast.id')
                ->pluck('cast.id')->map(fn ($id) => (int) $id)->all();
            $result[$day->work_date] = ['finalized' => (int) $day->status === ShiftScheduleDayStatus::Finalized->value, 'confirmed' => (int) $day->status >= ShiftScheduleDayStatus::Decided->value && $savedIds === $this->castIds($requestId, $day->work_date), 'count' => $count, 'required_count' => (int) $day->required_count];
        }

        return $result;
    }

    public function assignments(int $requestId, string $date): array
    {
        $day = DB::table('t_shift_schedule_days as day')
            ->where('day.shift_request_id', $requestId)->where('day.work_date', $date)
            ->whereNull('day.deleted_at')->select('day.id', 'day.status')->first();
        $saved = $day ? DB::table('t_shift_assignments')->where('shift_schedule_day_id', $day->id)->whereNull('deleted_at')->get()->keyBy('user_id') : collect();
        $submitted = DB::table('t_shift_details as detail')->join('t_shifts as shift', 'shift.id', '=', 'detail.shift_id')
            ->where('shift.shift_request_id', $requestId)->where('shift.status', ShiftStatus::Submitted->value)->where('detail.work_date', $date)
            ->whereNull('shift.deleted_at')->whereNull('detail.deleted_at')->select('detail.*', 'shift.user_id')->get()->keyBy('user_id');

        return DB::table('mst_users')->whereIn('id', $this->castIds($requestId, $date))->orderBy('id')->get()->map(function ($cast) use ($saved, $submitted, $day) {
            $value = $saved->get($cast->id) ?? $submitted->get($cast->id);
            $work = $value && (bool) ($saved->has($cast->id) ? $value->work : ((int) ($day->status ?? ShiftScheduleDayStatus::NotDecided->value) !== ShiftScheduleDayStatus::Finalized->value && $value->availability));

            return ['id' => (int) $cast->id, 'name' => $cast->name, 'work' => $work,
                'start' => $value?->start_time ? substr($value->start_time, 0, 5) : '20:00',
                'end' => $value?->end_time ? ((bool) $value->ends_next_day ? '24:00' : substr($value->end_time, 0, 5)) : '24:00',
                'reserved' => (bool) ($value->has_reservation ?? false), 'note' => $value->note ?? ''];
        })->all();
    }

    public function saveDay(int $requestId, string $date, array $casts, int $adminId, ?int $requiredCount = null): void
    {
        $now = CarbonImmutable::now('Asia/Tokyo');
        $key = ['shift_request_id' => $requestId, 'work_date' => $date];
        $existing = DB::table('t_shift_schedule_days')->where($key)->first();
        $attributes = ['status' => $existing && (int) $existing->status === ShiftScheduleDayStatus::Finalized->value ? ShiftScheduleDayStatus::Finalized->value : ShiftScheduleDayStatus::Decided->value, 'decided_by' => $adminId, 'decided_at' => $now, 'deleted_at' => null, 'updated_at' => $now];
        $attributes['required_count'] = $requiredCount ?? (int) ($existing->required_count ?? 0);
        if ($existing) {
            $dayId = (int) $existing->id;
            DB::table('t_shift_schedule_days')->where('id', $dayId)->update($attributes);
        } else {
            $dayId = DB::table('t_shift_schedule_days')->insertGetId([...$key, ...$attributes, 'created_at' => $now]);
        }
        DB::table('t_shift_assignments')->where('shift_schedule_day_id', $dayId)->update(['deleted_at' => $now, 'updated_at' => $now]);
        foreach ($casts as $cast) {
            $key = ['shift_schedule_day_id' => $dayId, 'user_id' => $cast['id']];
            $existing = DB::table('t_shift_assignments')->where($key)->first();
            $work = (bool) $cast['work'];
            $attributes = ['work' => $work, 'start_time' => $work ? $cast['start'] : null,
                'end_time' => $work ? ($cast['end'] === '24:00' ? '00:00' : $cast['end']) : null,
                'ends_next_day' => $work && $cast['end'] === '24:00', 'has_reservation' => $work && $cast['reserved'],
                'note' => $work ? ($cast['note'] ?? null) : null, 'deleted_at' => null, 'updated_at' => $now];
            if ($existing) {
                DB::table('t_shift_assignments')->where('id', $existing->id)->update($attributes);
            } else {
                DB::table('t_shift_assignments')->insert([...$key, ...$attributes, 'created_at' => $now]);
            }
        }
    }

    public function confirm(int $requestId, int $adminId, array $dates, bool $complete): void
    {
        DB::table('t_shift_schedule_days')->where('shift_request_id', $requestId)->whereIn('work_date', $dates)->whereNull('deleted_at')->update(['status' => ShiftScheduleDayStatus::Finalized->value, 'confirmed_by' => $adminId, 'confirmed_at' => CarbonImmutable::now('Asia/Tokyo'), 'updated_at' => now()]);
        if (! $complete) {
            return;
        }
        DB::table('t_shift_requests')->where('store_id', \App\Services\StoreAccess::storeId())->where('id', $requestId)->update(['status' => ShiftRequestStatus::Confirmed->value, 'updated_at' => now()]);
    }
}
