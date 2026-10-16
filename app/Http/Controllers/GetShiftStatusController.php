<?php

namespace App\Http\Controllers;

use App\Application\Shift\GetShiftStatusUseCase;
use App\Domain\Shift\CastShiftSubmission;
use App\Models\User;
use App\Domain\Shift\ShiftSubmissionPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 管理者向けに期間ごとのキャストのシフト提出状況を返す。
 */
final class GetShiftStatusController extends Controller
{
    public function __invoke(Request $request, GetShiftStatusUseCase $useCase): JsonResponse
    {
        abort_unless($request->user()->auth_type === 1, 403);

        $ulids = User::where('auth_type', 0)->pluck('ulid', 'id');
        $users = fn (array $casts) => array_map(
            fn (CastShiftSubmission $cast) => ['id' => $ulids[$cast->userId], 'name' => $cast->name],
            $casts,
        );

        return response()->json(['data' => array_map(
            fn (ShiftSubmissionPeriod $period) => [
                'id' => $period->requestId,
                'period_start' => $period->periodStart,
                'period_end' => $period->periodEnd,
                'submitted' => $users($period->submitted()),
                'unsubmitted' => $users($period->unsubmitted()),
            ],
            $useCase->execute(),
        )]);
    }
}
