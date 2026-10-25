<?php

namespace App\Domain\ShiftRequest;

interface ShiftRequestRepository
{
    /** @return list<RequestedShiftPeriod> */
    public function findAcceptingRequests(?int $userId = null): array;

    public function transaction(callable $operation): mixed;

    public function findForUpdate(int $id): ?ShiftRequest;

    public function update(int $id, ShiftRequest $request, bool $periodChanged): void;

    /** 保存した依頼のIDを返す。 */
    public function save(ShiftRequest $shiftRequest): int;
}
