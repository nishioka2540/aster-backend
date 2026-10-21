<?php

namespace App\Http\Controllers;

use App\Application\ShiftSchedule\ShiftScheduleUseCase;
use App\Http\Requests\DecideShiftScheduleRequest;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 管理者による日別シフトの編成・期間の確定と、キャストによる公開済みシフトの参照を受け付ける。
 */
final class ShiftScheduleController extends Controller
{
    public function mine(Request $request, ShiftScheduleUseCase $useCase)
    {
        abort_unless($request->user()->canRegisterShifts(), 403);

        return response()->json(['data' => $useCase->publishedForCast((int) $request->user()->id)]);
    }

    private function execute(Request $request, callable $operation)
    {
        abort_unless($request->user()->canManageShifts(), 403);
        try {
            return response()->json($operation());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['schedule' => $exception->getMessage()]);
        }
    }

    public function show(Request $request, int $requestId, ShiftScheduleUseCase $useCase)
    {
        return $this->execute($request, fn () => $useCase->review($requestId));
    }

    public function day(Request $request, int $requestId, string $date, ShiftScheduleUseCase $useCase)
    {
        $request->merge(['date' => $date]);
        $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return $this->execute($request, function () use ($useCase, $requestId, $date) {
            $result = $useCase->assignments($requestId, $date);
            $ulids = User::where('user_type', User::TYPE_CAST)->pluck('ulid', 'id');
            $result['casts'] = array_map(fn ($cast) => [...$cast, 'id' => $ulids[$cast['id']]], $result['casts']);
            $result['candidates'] = $result['confirmed']
                ? User::where('user_type', User::TYPE_CAST)->whereNotIn('ulid', array_column($result['casts'], 'id'))
                    ->orderBy('name')->get(['ulid', 'name'])->map(fn ($cast) => ['id' => $cast->ulid, 'name' => $cast->name])->all()
                : [];

            return $result;
        });
    }

    public function decide(DecideShiftScheduleRequest $request, int $requestId, string $date, ShiftScheduleUseCase $useCase)
    {
        $request->merge(['date' => $date]);
        $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return $this->execute($request, function () use ($request, $requestId, $date, $useCase) {
            $casts = array_map(function ($cast) {
                $user = User::where('user_type', User::TYPE_CAST)->where('ulid', $cast['id'])->first();
                if (! $user) {
                    throw ValidationException::withMessages(['casts' => 'キャストが見つかりません。']);
                }

                return [...$cast, 'id' => $user->id];
            }, $request->validated('casts'));
            $useCase->decide($requestId, $date, $casts, (int) $request->user()->id);

            return ['message' => '日別のシフトを保存しました。'];
        });
    }

    public function confirm(Request $request, int $requestId, ShiftScheduleUseCase $useCase)
    {
        $request->validate(['notify_line' => ['sometimes', 'boolean'], 'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'], 'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return $this->execute($request, function () use ($request, $requestId, $useCase) {
            return DB::transaction(function () use ($request, $requestId, $useCase) {
                $period = DB::table('t_shift_requests')->where('id', $requestId)->lockForUpdate()->first();
                abort_unless($period && ! $period->deleted_at, 404);
                $newDates = DB::table('t_shift_schedule_days')->where('shift_request_id', $requestId)
                    ->whereNull('deleted_at')->where('status', 1)
                    ->whereBetween('work_date', [$request->input('from') ?? $period->period_start, $request->input('to') ?? $period->period_end])
                    ->orderBy('work_date')->pluck('work_date');
                $useCase->confirm($requestId, (int) $request->user()->id, $request->input('from'), $request->input('to'));
                $message = '期間のシフトを確定しました。';
                if ($request->boolean('notify_line') && $newDates->isNotEmpty()) {
                    $message .= app(\App\Application\ShiftRequest\LineShiftNotifier::class)->enqueue($requestId, 'confirmed', $newDates->first(), $newDates->last());
                }

                return ['message' => $message];
            });
        });
    }
}
