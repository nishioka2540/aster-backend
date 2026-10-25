<?php

namespace Database\Seeders;

use Enum\UserType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NishiyamaStoreUserSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $storeId = DB::table('t_store')->where('ulid', NishiyamaStoreSeeder::STORE_ULID)
                ->whereNull('deleted_at')->sole()->id;

            foreach ([
                ['nishioka@example.com', UserType::Staff->value, 'staff', 'manager'],
                ['ayana@example.com', UserType::Cast->value, 'cast', 'cast'],
            ] as [$email, $type, $accessCode, $roleCode]) {
                $userId = DB::table('mst_users')->where('email', $email)->whereNull('deleted_at')->sole()->id;
                $accessId = DB::table('mst_access_roles')->where('code', $accessCode)->sole()->id;
                $roleId = $roleCode === null ? null : DB::table('mst_roles')
                    ->where(['store_id' => $storeId, 'access_role_id' => $accessId, 'code' => $roleCode])
                    ->whereNull('deleted_at')->sole()->id;

                // 再実行で管理画面の権限変更や退店状態を上書きしない。
                DB::table('t_store_users')->insertOrIgnore([
                    'store_id' => $storeId, 'user_id' => $userId, 'user_type' => $type,
                    'access_role_id' => $accessId, 'role_id' => $roleId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if (DB::table('t_store_users')->where('store_id', $storeId)->where('user_id', $userId)->whereNull('deleted_at')->exists()) {
                    $membership = DB::table('t_store_users')->where('store_id', $storeId)->where('user_id', $userId)->sole();
                    app(\App\Services\LoginIdGenerator::class)->assign(\App\Models\User::findOrFail($userId), (int) $storeId, (int) $membership->access_role_id);
                }
            }
        });
    }
}
