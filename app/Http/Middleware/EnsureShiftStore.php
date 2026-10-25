<?php

namespace App\Http\Middleware;

use App\Services\StoreAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class EnsureShiftStore
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('api/*') && (str_contains($request->path(), 'shift') || $request->is('api/admin/casts', 'api/admin/casts/*'))) {
            abort_unless($request->user(), 401);
            $storeId = StoreAccess::storeId();
            abort_unless($storeId, 403, '利用可能な店舗を選択してください。');
            if ($request->hasHeader('X-Store-Id')) {
                abort_unless((string) $storeId === $request->header('X-Store-Id'), 409, '選択中の店舗が変更されました。ページを再読み込みしてください。');
            }
            $user = $request->user();
            $allowed = match (true) {
                $request->is('api/admin/casts', 'api/admin/casts/*') => app(StoreAccess::class)->allows($user, 'cast.manage'),
                $request->is('api/shifts/confirmed-calendar') => $user->canViewConfirmedShifts(),
                $request->is('api/admin/*', 'api/shifts/status', 'api/shift-requests/defaults') => $user->canManageShifts(),
                $request->is('api/shift-requests/accepting') => $user->canManageShifts() || $user->canRegisterShifts(),
                $request->is('api/shift-requests', 'api/shift-requests/*') => $user->canManageShifts(),
                default => $user->canRegisterShifts(),
            };
            abort_unless($allowed, 403);
            $id = $request->route('requestId') ?? $request->input('shift_request_id');
            if ($id !== null && is_scalar($id) && ctype_digit((string) $id)) {
                abort_unless(DB::table('t_shift_requests')->where('id', $id)->where('store_id', $storeId)->whereNull('deleted_at')->exists(), 404);
            }
        }
        return $next($request);
    }
}
