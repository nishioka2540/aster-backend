<?php

namespace App\Http\Controllers;

use App\Application\Query\ShiftScheduleQueryService;
use App\Application\ShiftSchedule\ShiftScheduleUseCase;
use App\Http\Requests\DecideShiftScheduleRequest;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 日別シフトの編成・確定と公開済みシフトの参照を受け付ける。
 * 認可・入力検証後、Domainの検証・保存はUsecase、参照はQueryServiceへ委譲する。
 */
final class ShiftScheduleController extends Controller
{
    public function mine(Request $request, ShiftScheduleQueryService $service)
    {
        abort_unless($request->user()->canRegisterShifts(), 403);

        return response()->json(['data' => $service->publishedForCast((int) $request->user()->id)]);
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

    public function show(Request $request, int $requestId, ShiftScheduleQueryService $service)
    {
        return $this->execute($request, fn () => $service->review($requestId));
    }

    public function day(Request $request, int $requestId, string $date, ShiftScheduleQueryService $service)
    {
        $request->merge(['date' => $date]);
        $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return $this->execute($request, fn () => $service->assignments($requestId, $date));
    }

    public function decide(DecideShiftScheduleRequest $request, int $requestId, string $date, ShiftScheduleUseCase $useCase)
    {
        $request->merge(['date' => $date]);
        $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return $this->execute($request, function () use ($request, $requestId, $date, $useCase) {
            $useCase->decideForCasts($requestId, $date, $request->validated('casts'), (int) $request->user()->id, $request->has('required_count') ? (int) $request->validated('required_count') : null);

            return ['message' => '日別のシフトを保存しました。'];
        });
    }

    public function confirm(Request $request, int $requestId, ShiftScheduleUseCase $useCase)
    {
        $request->validate(['notify_line' => ['sometimes', 'boolean'], 'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'], 'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return $this->execute($request, function () use ($request, $requestId, $useCase) {
            return ['message' => $useCase->confirmWithNotification(
                $requestId, (int) $request->user()->id,
                $request->input('from'), $request->input('to'), $request->boolean('notify_line'),
            )];
        });
    }
}
