<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\StoreAccess;
use Illuminate\Http\Request;

final class UserContextController extends Controller
{
    public function show(Request $request, StoreAccess $access)
    {
        $user = $request->user();
        return response()->json([
            'id' => $user->id, 'name' => $user->name, 'login_id' => $user->login_id,
            'is_system_admin' => $user->is_system_admin, 'line_linked' => (bool) $user->line_id,
            'line_friend_url' => is_string($id = config('services.line.official_account_id')) && preg_match('/^@[a-zA-Z0-9._-]+$/D', $id)
                ? 'https://line.me/R/ti/p/'.rawurlencode($id) : null,
            ...$access->context($user), 'home_path' => $user->homePath(),
        ]);
    }

    public function select(Request $request, StoreAccess $access)
    {
        $data = $request->validate(['store_id' => ['required', 'integer']]);
        $storeId = (int) $data['store_id'];
        abort_unless(in_array($storeId, array_column($access->context($request->user())['stores'], 'id'), true), 403);
        $request->session()->put('active_store_id', $storeId);
        return $this->show($request, $access);
    }
}
