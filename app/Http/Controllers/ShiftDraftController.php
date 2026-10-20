<?php

namespace App\Http\Controllers;

use App\Application\Shift\SubmitShiftUseCase;
use App\Http\Requests\SaveShiftDraftRequest;
use DomainException;
use Enum\ShiftStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * キャストのシフト下書きの保存と、保存済みシフトの一覧取得を受け付ける。
 */
final class ShiftDraftController extends Controller
{
    public function store(SaveShiftDraftRequest $request, SubmitShiftUseCase $useCase): JsonResponse
    {
        $data = $request->validated();
        try {
            $id = $useCase->execute((int) $data['shift_request_id'], (int) $request->user()->getAuthIdentifier(), $data['days'], ShiftStatus::Draft);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['days' => $exception->getMessage()]);
        }
        return response()->json(['id' => $id, 'message' => '下書きを保存しました。']);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canRegisterShifts(), 403);
        $shifts = DB::table('t_shifts')->where('user_id', $request->user()->getAuthIdentifier())
            ->whereNull('deleted_at')->get();
        $requests = DB::table('t_shift_requests')->whereIn('id', $shifts->pluck('shift_request_id'))->whereNull('deleted_at')->get()->keyBy('id');
        $shifts = $shifts->filter(fn ($shift) => $requests->has($shift->shift_request_id));
        $details = DB::table('t_shift_details')->whereIn('shift_id', $shifts->pluck('id'))
            ->whereNull('deleted_at')->orderBy('work_date')->get()->groupBy('shift_id');
        return response()->json(['data' => $shifts->map(fn ($shift) => [
            'shift_request_id' => (int) $shift->shift_request_id, 'status' => (int) $shift->status, 'submitted_at' => $shift->submitted_at,
            'request' => $requests->get($shift->shift_request_id),
            'days' => ($details->get($shift->id) ?? collect())->map(fn ($day) => [
                'work_date' => $day->work_date, 'work' => (bool) $day->availability,
                'start_time' => $day->start_time ? substr($day->start_time, 0, 5) : null,
                'end_time' => $day->end_time ? substr($day->end_time, 0, 5) : null,
                'has_reservation' => (bool) $day->has_reservation, 'note' => $day->note ?? '',
            ])->values(),
        ])->values()]);
    }
}
