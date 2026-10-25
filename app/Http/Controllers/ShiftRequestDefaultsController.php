<?php

namespace App\Http\Controllers;

use App\Application\Query\ShiftRequestDefaultsQueryService;
use Illuminate\Http\Request;

/**
 * 管理者向けに次回のシフト依頼の初期値を返す。
 * 閲覧権限を検証し、日付候補と未確定依頼の取得をQueryServiceへ委譲する。
 */
final class ShiftRequestDefaultsController extends Controller
{
    public function __invoke(Request $request, ShiftRequestDefaultsQueryService $service)
    {
        abort_unless($request->user()->canManageShifts(), 403);

        return response()->json($service->get());
    }
}
