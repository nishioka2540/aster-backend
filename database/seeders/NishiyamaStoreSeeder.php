<?php

namespace Database\Seeders;

use Enum\UserType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NishiyamaStoreSeeder extends Seeder
{
    // 名前を変更しても再実行で別の組織・店舗を作らないための固定識別子。
    public const ORGANIZATION_ULID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    public const STORE_ULID = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

    public function run(): void
    {
        DB::transaction(function () {
            $now = now();
            foreach ([
                ['code' => 'cast', 'name' => 'キャスト', 'user_type' => UserType::Cast->value],
                ['code' => 'staff', 'name' => 'スタッフ', 'user_type' => UserType::Staff->value],
            ] as $accessRole) {
                DB::table('mst_access_roles')->updateOrInsert(['code' => $accessRole['code']], $accessRole);
            }
            foreach ([
                ['shift_calendar.view', '店舗全体の出勤カレンダー閲覧'],
                ['shift.manage', 'シフト管理'], ['shift.submit', '自分のシフト提出・確認'],
                ['cast.manage', 'キャスト管理'],
            ] as [$permissionCode, $permissionName]) {
                DB::table('mst_permissions')->updateOrInsert(['code' => $permissionCode], ['name' => $permissionName]);
            }
            DB::table('t_organizations')->insertOrIgnore([
                'ulid' => self::ORGANIZATION_ULID, 'name' => '株式会社にし山',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $organizationId = DB::table('t_organizations')->where('ulid', self::ORGANIZATION_ULID)->value('id');
            DB::table('t_store')->insertOrIgnore([
                'ulid' => self::STORE_ULID, 'organization_id' => $organizationId,
                'name' => 'clab にし山', 'timezone' => 'Asia/Tokyo',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $storeId = DB::table('t_store')->where('ulid', self::STORE_ULID)->value('id');
            $permissionId = DB::table('mst_permissions')->where('code', 'shift_calendar.view')->value('id');

            foreach ([
                [UserType::Cast->value, 'mama', 'ママ', 10],
                [UserType::Staff->value, 'manager', 'Manager', 20],
                [UserType::Staff->value, 'staff', 'Staff', 30],
                [UserType::Cast->value, 'cast', 'キャスト', 40],
            ] as [$type, $code, $name, $sortOrder]) {
                $accessId = DB::table('mst_access_roles')->where('code', $type === UserType::Cast->value ? 'cast' : 'staff')->sole()->id;
                DB::table('mst_roles')->insertOrIgnore([
                    'store_id' => $storeId, 'access_role_id' => $accessId, 'code' => $code,
                    'name' => $name, 'sort_order' => $sortOrder,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $roleId = DB::table('mst_roles')
                    ->where(['store_id' => $storeId, 'access_role_id' => $accessId, 'code' => $code])->value('id');
                $permissions = match ($code) {
                    'manager' => ['shift.manage', 'shift_calendar.view', 'cast.manage'],
                    'mama' => ['shift.submit', 'shift_calendar.view'],
                    'cast' => ['shift.submit'],
                    default => ['shift_calendar.view'],
                };
                foreach ($permissions as $permissionCode) {
                    DB::table('t_role_permissions')->insertOrIgnore([
                        'role_id' => $roleId, 'permission_id' => DB::table('mst_permissions')->where('code', $permissionCode)->sole()->id,
                    ]);
                }
            }
        });
    }
}
