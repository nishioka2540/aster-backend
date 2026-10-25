<?php

namespace App\Http\Controllers;

use App\Application\Query\AcceptingShiftRequestsQueryService;
use Illuminate\Http\JsonResponse;

/**
 * 受付中のシフト依頼の参照リクエストを受け付ける。
 * 権限を検証し、QueryServiceへ検索条件を渡して一覧を返す。
 */
final class GetAcceptingShiftRequestsController extends Controller
{
    public function __invoke(AcceptingShiftRequestsQueryService $service, \Illuminate\Http\Request $httpRequest): JsonResponse
    {
        abort_unless($httpRequest->user()->canManageShifts() || $httpRequest->user()->canRegisterShifts(), 403);

        return response()->json(['data' => $service->forUser(
            (int) $httpRequest->user()->getAuthIdentifier(),
            $httpRequest->user()->canManageShifts(),
            $httpRequest->boolean('upcoming_only'),
            $httpRequest->boolean('include_confirmed'),
        )]);
    }
}
