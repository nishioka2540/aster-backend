<?php

namespace App\Http\Controllers;

use App\Application\Query\ShiftStatusQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 管理者によるシフト提出状況の参照リクエストを受け付ける。
 * 閲覧権限を検証し、QueryServiceで取得・整形した一覧を返す。
 */
final class GetShiftStatusController extends Controller
{
    public function __invoke(Request $request, ShiftStatusQueryService $service): JsonResponse
    {
        abort_unless($request->user()->canManageShifts(), 403);

        return response()->json(['data' => $service->all()]);
    }
}
