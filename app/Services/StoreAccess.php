<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** 店舗所属・有効な役職から認可情報を組み立てる。 */
final class StoreAccess
{
    public const PERMISSIONS = ['shift.manage', 'shift.submit', 'shift_calendar.view', 'cast.manage'];

    public function memberships(User $user): array
    {
        return DB::table('t_store_users as membership')
            ->join('t_store as store', 'store.id', '=', 'membership.store_id')
            ->join('t_organizations as organization', 'organization.id', '=', 'store.organization_id')
            ->join('mst_access_roles as access', 'access.id', '=', 'membership.access_role_id')
            ->leftJoin('mst_roles as role', function ($join) {
                $join->on('role.id', '=', 'membership.role_id')->on('role.store_id', '=', 'membership.store_id')
                    ->on('role.access_role_id', '=', 'membership.access_role_id')->whereNull('role.deleted_at');
            })
            ->where('membership.user_id', $user->id)->whereNull('membership.deleted_at')
            ->whereNull('store.deleted_at')->whereNull('organization.deleted_at')
            ->orderBy('store.id')
            ->get(['membership.id', 'store.id as store_id', 'store.ulid as store_ulid', 'store.name as store_name',
                'access.id as access_role_id', 'access.code as access_code', 'access.name as access_name',
                'role.id as role_id', 'role.code as role_code', 'role.name as role_name'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'store' => ['id' => $row->store_id, 'ulid' => $row->store_ulid, 'name' => $row->store_name],
                'access_role' => ['id' => $row->access_role_id, 'code' => $row->access_code, 'name' => $row->access_name],
                'role' => $row->role_id ? ['id' => $row->role_id, 'code' => $row->role_code, 'name' => $row->role_name] : null,
                'permissions' => array_values(array_unique([
                    ...($row->access_code === 'cast' ? ['shift.submit'] : []),
                    ...($row->role_id ? DB::table('t_role_permissions as grant')
                        ->join('mst_permissions as permission', 'permission.id', '=', 'grant.permission_id')
                        ->where('grant.role_id', $row->role_id)->orderBy('permission.code')->pluck('permission.code')->all() : []),
                ])),
            ])->all();
    }

    public function context(User $user): array
    {
        $selected = request()->hasSession() ? request()->session()->get('active_store_id') : null;
        $cacheKey = 'store_access.'.$user->id.'.'.(int) $user->is_system_admin.'.'.($selected ?? 'default');
        if (request()->attributes->has($cacheKey)) return request()->attributes->get($cacheKey);
        $memberships = $this->memberships($user);
        $stores = $user->is_system_admin
            ? DB::table('t_store as store')->join('t_organizations as org', 'org.id', '=', 'store.organization_id')
                ->whereNull('store.deleted_at')->whereNull('org.deleted_at')->orderBy('store.id')
                ->get(['store.id', 'store.ulid', 'store.name'])->map(fn ($store) => (array) $store)->all()
            : array_column($memberships, 'store');
        $active = null;
        foreach ($stores as $store) {
            if ((string) $store['id'] === (string) $selected) $active = $store;
        }
        // 選択済み店舗への権限が失効した場合は別店舗へ自動で切り替えない。
        if ($selected === null) $active = $stores[0] ?? null;
        $membership = null;
        foreach ($memberships as $item) {
            if ($item['store']['id'] === ($active['id'] ?? null)) $membership = $item;
        }

        $context = ['memberships' => $memberships, 'stores' => $stores, 'active_store' => $active,
            'active_membership' => $membership,
            'permissions' => $active ? ($user->is_system_admin ? self::PERMISSIONS : ($membership['permissions'] ?? [])) : []];
        request()->attributes->set($cacheKey, $context);
        return $context;
    }

    public function allows(User $user, string $permission): bool
    {
        return in_array($permission, $this->context($user)['permissions'], true);
    }

    public static function storeId(): int
    {
        $user = request()->user();
        return $user ? (int) (app(self::class)->context($user)['active_store']['id'] ?? 0) : 0;
    }

    public static function castIds(?int $storeId = null): array
    {
        return DB::table('t_store_users as membership')->join('mst_access_roles as access', 'access.id', '=', 'membership.access_role_id')
            ->join('mst_users as user', 'user.id', '=', 'membership.user_id')
            ->join('t_store as store', 'store.id', '=', 'membership.store_id')
            ->join('t_organizations as org', 'org.id', '=', 'store.organization_id')
            ->where('membership.store_id', $storeId ?? self::storeId())->where('access.code', 'cast')
            ->whereNull('membership.deleted_at')->whereNull('user.deleted_at')->whereNull('store.deleted_at')->whereNull('org.deleted_at')
            ->pluck('user.id')->map(fn ($id) => (int) $id)->all();
    }
}
