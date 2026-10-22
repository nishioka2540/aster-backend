<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmedShiftCalendarController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->canViewConfirmedShifts(), 403);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($data['from'], 'Asia/Tokyo');
        $to = CarbonImmutable::parse($data['to'], 'Asia/Tokyo');
        if ($from->diffInDays($to) > 62) {
            throw ValidationException::withMessages(['to' => '表示期間は63日以内で指定してください。']);
        }

        // Publish finalized days even when the rest of the request is still open.
        $days = DB::table('t_shift_schedule_days as day')
            ->join('t_shift_requests as request', 'request.id', '=', 'day.shift_request_id')
            ->where('day.status', 2)->whereNull('day.deleted_at')->whereNull('request.deleted_at')
            ->whereBetween('day.work_date', [$data['from'], $data['to']])
            ->select('day.id', 'day.work_date', 'day.shift_request_id')->get();
        $casts = DB::table('t_shift_assignments as assignment')
            ->join('mst_users as cast', 'cast.id', '=', 'assignment.user_id')
            ->whereIn('assignment.shift_schedule_day_id', $days->pluck('id'))
            ->where('assignment.work', true)->whereNull('assignment.deleted_at')
            ->where('cast.user_type', 2)->whereNull('cast.deleted_at')
            ->orderBy('assignment.start_time')->orderBy('cast.id')
            ->select('assignment.shift_schedule_day_id', 'cast.ulid', 'cast.name',
                'assignment.start_time', 'assignment.end_time', 'assignment.ends_next_day')->get()
            ->groupBy('shift_schedule_day_id');
        $byDate = $days->groupBy('work_date');
        $result = [];
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $dateDays = $byDate->get($date->toDateString(), collect());
            $members = $dateDays->flatMap(fn ($day) => $casts->get($day->id, collect()))
                ->unique('ulid')->sortBy('start_time')->values()->map(fn ($cast) => [
                    'id' => $cast->ulid, 'name' => $cast->name,
                    'start' => substr($cast->start_time, 0, 5),
                    'end' => $cast->ends_next_day ? '24:00' : substr($cast->end_time, 0, 5),
                ])->all();
            $result[] = ['date' => $date->toDateString(), 'confirmed' => $dateDays->isNotEmpty(),
                'count' => count($members), 'casts' => $members,
                ...($request->user()->canManageShifts() ? ['request_ids' => $dateDays->pluck('shift_request_id')->unique()->values()->all()] : [])];
        }

        return response()->json(['data' => $result]);
    }
}
