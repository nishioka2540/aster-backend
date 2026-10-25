<?php

namespace App\Application\Shift;

use App\Application\ShiftRequest\ShiftRequestAvailabilityQueryService;
use Enum\ShiftStatus;
use Illuminate\Support\Facades\DB;

final class CastHomePath
{
    public function forUser(int $userId): string
    {
        $requests = app(ShiftRequestAvailabilityQueryService::class)->findAcceptingRequests($userId);
        $ids = array_map(fn ($request) => $request->id, $requests);
        $submitted = DB::table('t_shifts')->where('user_id', $userId)->where('status', ShiftStatus::Submitted->value)
            ->whereNull('deleted_at')->whereIn('shift_request_id', $ids)->pluck('shift_request_id')->all();

        return array_diff($ids, $submitted) ? '/submission' : '/my_shifts';
    }
}
