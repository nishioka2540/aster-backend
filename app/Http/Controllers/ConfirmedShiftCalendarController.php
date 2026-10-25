<?php

namespace App\Http\Controllers;

use App\Application\Query\ConfirmedShiftCalendarQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 確定シフトの表示期間と閲覧権限を検証し、QueryServiceの取得結果を返す。
 */
final class ConfirmedShiftCalendarController extends Controller
{
    public function __invoke(Request $request, ConfirmedShiftCalendarQueryService $service): JsonResponse
    {
        abort_unless($request->user()->canViewConfirmedShifts(), 403);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($data['from'], 'Asia/Tokyo');
        $to = CarbonImmutable::parse($data['to'], 'Asia/Tokyo');
        if ($from->diffInDays($to) > 62) {
            throw ValidationException::withMessages(['to' => '表示期間は63日以内で指定してください。']);
        }

        return response()->json(['data' => $service->between($from, $to, $request->user()->canManageShifts())]);
    }
}
