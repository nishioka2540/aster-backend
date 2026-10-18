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
            return response()->json(['data' => \Illuminate\Support\Facades\DB::table('t_shift_requests')->whereNull('deleted_at')->when(!$httpRequest->boolean('include_confirmed'), fn ($query) => $query->where('status', 0))->orderBy('status')->orderByDesc('period_start')->get(['id', 'period_start', 'period_end', 'submission_deadline', 'notice'])]);
        }
        return response()->json(['data' => array_map(
            fn (RequestedShiftPeriod $request) => [
                'id' => $request->id, 'period_start' => $request->periodStart,
                'period_end' => $request->periodEnd,
                'submission_deadline' => $request->submissionDeadline, 'notice' => $request->notice,
                'finalized_dates' => \Illuminate\Support\Facades\DB::table('t_shift_schedule_days')->where('shift_request_id', $request->id)->where('status', 2)->whereNull('deleted_at')->pluck('work_date')->all(),
            ], $useCase->execute(),
        )]);
    }
}
