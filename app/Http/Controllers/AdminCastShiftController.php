<?php

namespace App\Http\Controllers;

use App\Application\Shift\SubmitShiftUseCase;
use App\Http\Requests\AdminSubmitShiftRequest;
use App\Models\User;
use DomainException;
use Enum\ShiftStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 管理者によるキャストの提出シフトの参照・代理保存を受け付ける。
 */
final class AdminCastShiftController extends Controller
{
    public function show(Request $request, int $requestId, string $castId): JsonResponse
    {
        abort_unless($request->user()->auth_type === 1, 403);
        $cast = User::where('auth_type', 0)->where('ulid', $castId)->firstOrFail();
        $period = DB::table('t_shift_requests')->where('id', $requestId)->whereNull('deleted_at')->first();
        abort_unless($period, 404);
        $period->finalized_dates = DB::table('t_shift_schedule_days')->where('shift_request_id', $requestId)->where('status', 2)->whereNull('deleted_at')->pluck('work_date')->all();
        $shift = DB::table('t_shifts')->where('shift_request_id', $requestId)
            ->where('user_id', $cast->id)->where('status', ShiftStatus::Submitted->value)->whereNull('deleted_at')->first();
        $days = $shift ? DB::table('t_shift_details')->where('shift_id', $shift->id)
            ->whereNull('deleted_at')->orderBy('work_date')->get()->map(fn ($day) => [
                'work_date' => $day->work_date,
                'work' => (bool) $day->availability,
                'start_time' => $day->start_time ? substr($day->start_time, 0, 5) : null,
                'end_time' => $day->end_time ? substr($day->end_time, 0, 5) : null,
                'has_reservation' => (bool) $day->has_reservation,
                'note' => $day->note ?? '',
            ])->all() : [];

        return response()->json(['cast' => ['id' => $cast->ulid, 'name' => $cast->name],
            'request' => $period, 'submitted' => $shift && (int) $shift->status === 2, 'days' => $days]);
    }

    public function update(AdminSubmitShiftRequest $request, int $requestId, string $castId, SubmitShiftUseCase $useCase): JsonResponse
    {
        $cast = User::where('auth_type', 0)->where('ulid', $castId)->firstOrFail();
        $data = $request->validated();
        abort_unless((int) $data['shift_request_id'] === $requestId, 422);
        try {
            $id = DB::transaction(function () use ($requestId, $cast, $data, $useCase) {
                // キャストの保存と同じ依頼行をロックし、下書きへの変更と競合させない。
                DB::table('t_shift_requests')->where('id', $requestId)->lockForUpdate()->first();
                return $useCase->execute($requestId, $cast->id, $data['days']);
            });
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['days' => $exception->getMessage()]);
        }

        return response()->json(['id' => $id, 'message' => 'シフトを保存しました。']);
    }
}
