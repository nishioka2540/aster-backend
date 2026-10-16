<?php
namespace App\Http\Controllers;

use App\Application\ShiftRequest\ShiftRequestAvailabilityQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * 管理者向けに確定済みシフトの最終日と、次の提出依頼の開始日候補を返す。
 */
final class ShiftRequestDefaultsController extends Controller
{
    public function __invoke(Request $request, ShiftRequestAvailabilityQueryService $availability)
    {
        abort_unless($request->user()->auth_type === 1, 403);
        $end = $availability->latestConfirmedEnd();
        $start = $end ? CarbonImmutable::parse($end, 'Asia/Tokyo')->addWeekday() : CarbonImmutable::today('Asia/Tokyo');
        while ($start->isWeekend()) $start = $start->addDay();
        return response()->json(['period_start' => $start->toDateString(), 'confirmed_end' => $end, 'has_unconfirmed_request' => \Illuminate\Support\Facades\DB::table('t_shift_requests')->whereNull('deleted_at')->where('status', 0)->exists()]);
    }
}
