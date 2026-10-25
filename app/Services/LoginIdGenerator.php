<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class LoginIdGenerator
{
    public function assign(User $user, int $storeId, int $accessRoleId): string
    {
        return DB::transaction(function () use ($user, $storeId, $accessRoleId) {
            $saved = User::withTrashed()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            // 最初の所属で一度だけ発行し、再入店や他店舗への所属で変更しない。
            if ($saved->login_id !== null) {
                $user->login_id = $saved->login_id;
                return $saved->login_id;
            }
            if (! DB::table('t_store_users')->where('user_id', $user->id)->where('store_id', $storeId)
                ->where('access_role_id', $accessRoleId)->whereNull('deleted_at')->exists()) {
                throw new \InvalidArgumentException('An active store membership is required to assign a login ID.');
            }
            $loginId = str_pad((string) $storeId, 2, '0', STR_PAD_LEFT)
                .str_pad((string) $accessRoleId, 2, '0', STR_PAD_LEFT).'A'
                .str_pad((string) $saved->id, 4, '0', STR_PAD_LEFT);
            DB::table('mst_users')->where('id', $saved->id)->update(['login_id' => $loginId]);
            $user->login_id = $loginId;
            $user->syncOriginalAttribute('login_id');
            return $loginId;
        });
    }
}
