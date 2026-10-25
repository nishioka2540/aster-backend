<?php

namespace App\Application\Query;

use App\Services\StoreAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CastManagementQueryService
{
    public function query(): Builder
    {
        // 退職済み所属も含める。ユーザー自体が削除された場合は対象外。
        return DB::table('t_store_users as membership')
            ->join('mst_users as user', 'user.id', '=', 'membership.user_id')
            ->join('mst_access_roles as access', 'access.id', '=', 'membership.access_role_id')
            ->leftJoin('mst_roles as role', function ($join) {
                $join->on('role.id', '=', 'membership.role_id')->whereNull('role.deleted_at');
            })
            ->where('membership.store_id', StoreAccess::storeId())->where('access.code', 'cast')
            ->whereNull('user.deleted_at');
    }

    public function all(): array
    {
        return $this->query()->orderBy('user.name')->orderBy('membership.id')
            ->get($this->columns())->map(fn ($row) => $this->serialize($row))->all();
    }

    public function find(int $membershipId): array
    {
        $row = $this->query()->where('membership.id', $membershipId)->first($this->columns());
        abort_unless($row, 404);
        return $this->serialize($row);
    }

    public function roles(): array
    {
        return DB::table('mst_roles as role')->join('mst_access_roles as access', 'access.id', '=', 'role.access_role_id')
            ->where('role.store_id', StoreAccess::storeId())->where('access.code', 'cast')->whereNull('role.deleted_at')
            ->orderBy('role.sort_order')->orderBy('role.id')->get(['role.id', 'role.name'])
            ->map(fn ($role) => ['id' => (int) $role->id, 'name' => $role->name,
                'permissions' => DB::table('t_role_permissions as grant')->join('mst_permissions as permission', 'permission.id', '=', 'grant.permission_id')
                    ->where('grant.role_id', $role->id)->orderBy('permission.code')->get(['permission.code', 'permission.name'])->all(),
            ])->all();
    }

    private function columns(): array
    {
        return ['membership.id', 'user.ulid as user_ulid', 'user.name', 'user.login_id', 'user.line_id',
            'role.id as role_id', 'role.name as role_name', 'membership.deleted_at as retired_at'];
    }

    private function serialize(object $row): array
    {
        return ['id' => (int) $row->id, 'user_id' => $row->user_ulid, 'name' => $row->name,
            'login_id' => $row->login_id, 'role_id' => $row->role_id !== null ? (int) $row->role_id : null, 'role_name' => $row->role_name,
            'line_linked' => (bool) $row->line_id, 'retired' => $row->retired_at !== null, 'retired_at' => $row->retired_at];
    }
}
