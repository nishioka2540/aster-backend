<?php
namespace App\Http\Controllers;
use App\Application\ShiftRequest\GetAcceptingShiftRequestsUseCase;
use App\Domain\ShiftRequest\RequestedShiftPeriod;
use Illuminate\Http\JsonResponse;
/**
 * 現在受付中のシフト提出依頼の一覧を返す。
 */
final class GetAcceptingShiftRequestsController extends Controller
{
    public function __invoke(GetAcceptingShiftRequestsUseCase $useCase, \Illuminate\Http\Request $httpRequest): JsonResponse
    {
        if ($httpRequest->user()->auth_type === 1) {
            return response()->json(['data' => \Illuminate\Support\Facades\DB::table('t_shift_requests')->whereNull('deleted_at')->where('status', 0)->orderBy('period_start')->get(['id', 'period_start', 'period_end', 'submission_deadline', 'notice'])]);
        }
        return response()->json(['data' => array_map(
            fn (RequestedShiftPeriod $request) => [
                'id' => $request->id, 'period_start' => $request->periodStart,
                'period_end' => $request->periodEnd,
                'submission_deadline' => $request->submissionDeadline, 'notice' => $request->notice,
            ], $useCase->execute(),
        )]);
    }
}
