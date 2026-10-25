<?php

namespace App\Http\Controllers;

use App\Application\CastManagement\CastInvitationException;
use App\Application\CastManagement\CastInvitationService;
use App\Services\StoreAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CastInvitationController extends Controller
{
    public function show(Request $request, StoreAccess $access, CastInvitationService $service)
    {
        abort_unless($access->allows($request->user(), 'cast.manage'), 403);
        return response()->json($service->current(StoreAccess::storeId()))->header('Cache-Control', 'no-store');
    }

    public function issue(Request $request, StoreAccess $access, CastInvitationService $service)
    {
        abort_unless($access->allows($request->user(), 'cast.manage'), 403);
        return response()->json($service->issue(StoreAccess::storeId()))->header('Cache-Control', 'no-store');
    }

    public function resolve(Request $request, CastInvitationService $service)
    {
        try {
            $store = $service->resolve($request->query('store'), $request->query('invite'));
            return response()->json(['store_name' => $store->name, 'store_code' => $store->ulid])->header('Cache-Control', 'no-store');
        } catch (CastInvitationException $exception) {
            return response()->json(['message' => $exception->getMessage() === 'invitation_expired'
                ? '招待QRの有効期限が切れました。店舗管理者に再発行を依頼してください。'
                : '招待リンクが無効です。店舗管理者から再取得してください。'], 422);
        }
    }
}
