<?php

namespace App\Application\CastManagement;

use App\Application\Query\CastManagementQueryService;
use App\Services\StoreAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateCastRoleUseCase
{
    public function execute(int $membershipId, ?int $roleId, CastManagementQueryService $query): array
    {
        return DB::transaction(function () use ($membershipId, $roleId, $query) {
            $membership = DB::table('t_store_users')->where('id', $membershipId)
                ->where('store_id', StoreAccess::storeId())->lockForUpdate()->first();
            abort_unless($membership, 404);
            $query->find($membershipId);
            if ($roleId !== null) {
                $role = DB::table('mst_roles')->where('id', $roleId)->where('store_id', $membership->store_id)
                    ->where('access_role_id', $membership->access_role_id)->whereNull('deleted_at')->lockForUpdate()->first();
                if (! $role) throw ValidationException::withMessages(['role_id' => 'この店舗のキャスト用の有効な役職を選択してください。']);
            }
            if ($membership->role_id !== $roleId) {
                DB::table('t_store_users')->where('id', $membershipId)->update(['role_id' => $roleId, 'updated_at' => now()]);
            }
            return $query->find($membershipId);
        });
    }
}
