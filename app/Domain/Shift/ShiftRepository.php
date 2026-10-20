<?php

namespace App\Domain\Shift;

interface ShiftRepository
{
    public function transaction(callable $operation): int;

    /** 受付状態を確認する間、依頼をロックする。 */
    public function findRequestForUpdate(int $requestId, int $userId): ?array;

    public function save(Shift $shift): int;
}
