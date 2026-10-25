<?php

namespace App\Application\Shift;

use App\Models\User;
use Enum\UserType;
use Illuminate\Support\Facades\DB;

/** 代理保存の対象を解決し、Domain生成・保存を通常提出と同じUsecaseへ委譲する。 */
final readonly class AdminSubmitShiftUseCase
{
    public function __construct(private SubmitShiftUseCase $submit) {}

    public function execute(int $requestId, string $castId, array $days): int
    {
        $cast = User::whereIn('id', \App\Services\StoreAccess::castIds())->where('ulid', $castId)->firstOrFail();

        return DB::transaction(function () use ($requestId, $cast, $days) {
            DB::table('t_shift_requests')->where('id', $requestId)->lockForUpdate()->first();

            return $this->submit->execute($requestId, $cast->id, $days, byAdmin: true);
        });
    }
}
