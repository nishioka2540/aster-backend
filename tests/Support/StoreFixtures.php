<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 既存のシフト業務テストに、明示的な店舗所属と権限のfixtureを用意する。 */
final class StoreFixtures
{
    public static function storeId(): int
    {
        $id = DB::table('t_store')->where('name', 'テスト店舗')->value('id');
        if ($id) return (int) $id;
        $org = DB::table('t_organizations')->insertGetId(['ulid' => (string) Str::ulid(), 'name' => 'テスト組織']);
        return DB::table('t_store')->insertGetId(['ulid' => (string) Str::ulid(), 'organization_id' => $org, 'name' => 'テスト店舗']);
    }

    public static function enroll(User $user, int $type = 2, int $roleType = 3, int $manager = 0): void
    {
        $store = self::storeId();
        $access = DB::table('mst_access_roles')->where('code', $type === 2 ? 'cast' : 'staff')->sole()->id;
        $permissions = $type === 2 ? ['shift.submit'] : ['shift_calendar.view'];
        if ($type === 2 && $roleType === 1) $permissions[] = 'shift_calendar.view';
        if ($type === 1 && in_array($roleType, [1, 2])) $permissions = ['shift.manage', 'shift_calendar.view'];
        if ($manager === 1) $permissions = ['shift.manage', 'shift.submit', 'shift_calendar.view'];
        $code = 'fixture-'.$type.'-'.$roleType.'-'.$manager;
        DB::table('mst_roles')->insertOrIgnore(['store_id' => $store, 'access_role_id' => $access, 'code' => $code, 'name' => $code]);
        $role = DB::table('mst_roles')->where(['store_id' => $store, 'code' => $code])->sole()->id;
        foreach ($permissions as $permission) {
            DB::table('t_role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => DB::table('mst_permissions')->where('code', $permission)->sole()->id]);
        }
        DB::table('t_store_users')->updateOrInsert(['store_id' => $store, 'user_id' => $user->id], [
            'user_type' => $type, 'access_role_id' => $access, 'role_id' => $role,
        ]);
        app(\App\Services\LoginIdGenerator::class)->assign($user, $store, (int) $access);
    }
}
