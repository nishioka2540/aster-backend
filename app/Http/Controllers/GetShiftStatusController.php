<?php

namespace App\Http\Controllers;

use App\Application\Shift\GetShiftStatusUseCase;
use App\Domain\Shift\CastShiftSubmission;
use App\Domain\Shift\ShiftSubmissionPeriod;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 管理者向けに期間ごとのキャストのシフト提出状況を返す。
 */
final class GetShiftStatusController extends Controller
{
    public function __invoke(Request $request, GetShiftStatusUseCase $useCase): JsonResponse
    {
        abort_unless($request->user()->canManageShifts(), 403);

        $ulids = User::where('user_type', User::TYPE_CAST)->pluck('ulid', 'id');
        $users = fn (array $casts) => array_map(
            fn (CastShiftSubmission $cast) => ['id' => $ulids[$cast->userId], 'name' => $cast->name],
            $casts,
        );

        return response()->json(['data' => array_map(
            fn (ShiftSubmissionPeriod $period) => [
                'id' => $period->requestId,
                'period_start' => $period->periodStart,
                'period_end' => $period->periodEnd,
                'submission_deadline' => $period->submissionDeadline,
                'reminder_available' => $period->submissionDeadline < now('Asia/Tokyo')->startOfDay()->format('Y-m-d H:i:s'),
                'submitted' => $users($period->submitted()),
                'unsubmitted' => $users($period->unsubmitted()),
            ],
            $useCase->execute(),
        )]);
    }
}
