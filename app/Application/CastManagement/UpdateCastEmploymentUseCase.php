<?php

namespace App\Application\CastManagement;

use App\Application\Query\CastManagementQueryService;
use App\Services\StoreAccess;
use Illuminate\Support\Facades\DB;

final class UpdateCastEmploymentUseCase
{
    public function execute(int $membershipId, bool $retired, CastManagementQueryService $query): array
    {
        return DB::transaction(function () use ($membershipId, $retired, $query) {
            $membership = DB::table('t_store_users')->where('id', $membershipId)
                ->where('store_id', StoreAccess::storeId())->lockForUpdate()->first();
            abort_unless($membership, 404);
            // キャスト区分かつユーザーが有効な所属だけを更新する。
            $cast = $query->find($membershipId);
            if ($cast['retired'] !== $retired) {
                DB::table('t_store_users')->where('id', $membershipId)->update([
                    'deleted_at' => $retired ? now() : null, 'updated_at' => now(),
                ]);
            }
            return $query->find($membershipId);
        });
    }
}
